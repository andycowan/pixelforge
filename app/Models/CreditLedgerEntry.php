<?php

namespace App\Models;

use App\Enums\CreditEntryType;
use App\Exceptions\InsufficientCredit;
use App\Exceptions\ProviderEvidenceConflict;
use App\Services\GenerationPricing;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * One immutable credit ledger entry.
 *
 * Available credit = purchases + releases - holds - debits, always summed from
 * these rows. A generation has at most one hold, one release and one debit
 * (unique generation/type). Settlement releases the WHOLE hold and debits the
 * actual charge as a separate entry, so every terminal outcome is uniform and
 * the debit always equals the generation charge's customer cost.
 *
 * @property int $id
 * @property int|null $user_id
 * @property CreditEntryType $type
 * @property int $amount_nanodollars
 * @property string|null $generation_id
 * @property string|null $idempotency_key
 * @property string|null $reason
 * @property CarbonInterface $created_at
 */
final class CreditLedgerEntry extends Model
{
    /** Largest single purchase the model accepts, whatever the HTTP packages say. */
    public const MAX_PURCHASE_NANODOLLARS = 100 * 1_000_000_000;

    /** Signed contribution of a row to available credit (D-B01, D-B16). */
    private const AVAILABLE_SQL = "CASE WHEN type IN ('purchase', 'release', 'credit_adjustment') THEN amount_nanodollars ELSE -amount_nanodollars END";

    /** Signed contribution of a row to credit held for unfinished generations. */
    private const HELD_SQL = "CASE type WHEN 'hold' THEN amount_nanodollars WHEN 'release' THEN -amount_nanodollars ELSE 0 END";

    /** Largest single operator adjustment; a typo guard, not a business limit (D-B16). */
    public const MAX_ADJUSTMENT_NANODOLLARS = 1_000 * 1_000_000_000;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    private bool $appending = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CreditEntryType::class,
            'amount_nanodollars' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * Credit the user could spend now. Negative only after a charge exceeded
     * its hold (D-B03).
     */
    public static function availableFor(User $user): int
    {
        // shortcut: sums the user's whole ledger on every read; add a periodic balance snapshot if per-user entries grow into the hundreds of thousands.
        return self::sumFor($user, self::AVAILABLE_SQL);
    }

    /** Credit reserved by generations whose outcome is not settled yet. */
    public static function heldFor(User $user): int
    {
        return self::sumFor($user, self::HELD_SQL);
    }

    /**
     * Customer credit held across ALL users right now (the operator "bank"),
     * with the same formulas as per-user balances so the two always agree.
     * Includes ownerless rows: deleting a user does not spend their credit.
     *
     * @return array{available: int, held: int}
     */
    public static function bank(): array
    {
        $row = self::query()->selectRaw('COALESCE(SUM('.self::AVAILABLE_SQL.'), 0) AS available, COALESCE(SUM('.self::HELD_SQL.'), 0) AS held')->toBase()->first();

        return ['available' => (int) ($row->available ?? 0), 'held' => (int) ($row->held ?? 0)];
    }

    /** @return array{available_usd: string, held_usd: string} */
    public static function balanceFor(User $user): array
    {
        return [
            'available_usd' => self::format(self::availableFor($user)),
            'held_usd' => self::format(self::heldFor($user)),
        ];
    }

    public static function canAfford(User $user, int $nanodollars): bool
    {
        return self::availableFor($user) >= $nanodollars;
    }

    /**
     * Append a purchase once per client idempotency key. A repeated key returns
     * the original entry unchanged; callers decide whether its amount conflicts.
     */
    public static function purchase(User $user, int $nanodollars, string $idempotencyKey): self
    {
        self::assertTransaction();
        if ($nanodollars <= 0 || $nanodollars > self::MAX_PURCHASE_NANODOLLARS || $nanodollars % GenerationPricing::CENT !== 0) {
            throw new InvalidArgumentException('A credit purchase must be a positive whole-cent amount within the purchase limit.');
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('A credit purchase requires a UUID idempotency key.');
        }
        self::lockOwner($user->getKey());
        $existing = self::query()->where('user_id', $user->getKey())->where('idempotency_key', $idempotencyKey)->first();

        return $existing ?? self::record($user->getKey(), CreditEntryType::Purchase, $nanodollars, idempotencyKey: $idempotencyKey);
    }

    /**
     * Record an operator adjustment: positive adds credit, negative removes it
     * (D-B16). The caller decides the amount, typically under the owner lock.
     */
    public static function adjust(User $user, int $signedNanodollars, string $reason): self
    {
        self::assertTransaction();
        $amount = abs($signedNanodollars);
        if ($amount === 0 || $amount > self::MAX_ADJUSTMENT_NANODOLLARS || $amount % GenerationPricing::CENT !== 0) {
            throw new InvalidArgumentException('A credit adjustment must be a nonzero whole-cent amount within the adjustment limit.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A credit adjustment requires a reason of at most 500 characters.');
        }
        self::lockOwner($user->getKey());
        $type = $signedNanodollars > 0 ? CreditEntryType::CreditAdjustment : CreditEntryType::DebitAdjustment;

        return self::record($user->getKey(), $type, $amount, reason: $reason);
    }

    /**
     * Reserve the generation's quoted maximum. Locks the owner row (a no-op if
     * the caller already holds it) so concurrent requests see each other's holds.
     *
     * @throws InsufficientCredit
     */
    public static function holdFor(Generation $generation): self
    {
        self::assertTransaction();
        if (! $generation->exists || $generation->user_id === null) {
            throw new LogicException('A credit hold requires a persisted, owned generation.');
        }
        $owner = self::lockOwner($generation->user_id);
        $available = self::availableFor($owner);
        if ($available < $generation->maximum_cost_nanodollars) {
            throw new InsufficientCredit($generation->maximum_cost_nanodollars, $available);
        }

        return self::record($generation->user_id, CreditEntryType::Hold, $generation->maximum_cost_nanodollars, $generation->id);
    }

    /**
     * Return the whole hold to available credit. Idempotent; generations from
     * before the ledger have no hold and get no entries.
     */
    public static function releaseHoldFor(Generation $generation): ?self
    {
        self::assertTransaction();
        $hold = self::entryFor($generation, CreditEntryType::Hold);
        if ($hold === null) {
            return null;
        }

        return self::entryFor($generation, CreditEntryType::Release)
            ?? self::record($hold->user_id, CreditEntryType::Release, $hold->amount_nanodollars, $generation->id);
    }

    /**
     * Release the hold and debit exactly the settled customer charge, once.
     * The debit is not capped by the hold (D-B03).
     */
    public static function settle(Generation $generation, GenerationCharge $charge): ?self
    {
        self::assertTransaction();
        if ($charge->generation_id !== $generation->id) {
            throw new LogicException('A debit must settle its own generation charge.');
        }
        $hold = self::entryFor($generation, CreditEntryType::Hold);
        if ($hold === null) {
            return null; // Pre-ledger generation: no retroactive debit.
        }
        self::releaseHoldFor($generation);
        $debit = self::entryFor($generation, CreditEntryType::Debit);
        if ($debit !== null) {
            if ($debit->amount_nanodollars !== $charge->customer_cost_nanodollars) {
                throw new ProviderEvidenceConflict('Existing debit does not agree with the settled charge.');
            }

            return $debit;
        }

        return self::record($hold->user_id, CreditEntryType::Debit, $charge->customer_cost_nanodollars, $generation->id);
    }

    /**
     * Recent activity for display. A released hold nets to zero, so it and its
     * release are omitted; active holds, purchases and debits remain (D-B13).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentFor(User $user, int $limit = 50): array
    {
        return self::query()->where('user_id', $user->getKey())
            ->whereNot(fn (Builder $query) => $query->whereIn('type', [CreditEntryType::Hold, CreditEntryType::Release])
                ->whereIn('generation_id', self::query()->select('generation_id')->where('type', CreditEntryType::Release)))
            ->with('generation:id,prompt')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (self $entry): array => $entry->toHistoryArray())->values()->all();
    }

    /** @return array<string, mixed> */
    public function toHistoryArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_usd' => self::format($this->amount_nanodollars),
            'generation_id' => $this->generation_id,
            // Generation entries show a short single-line prompt excerpt (the full prompt stays on Generations); adjustments show their reason.
            'detail' => $this->reason ?? ($this->generation === null ? null : Str::limit(Str::squish($this->generation->prompt), 60, '…')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toHistoryArray();
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = [])
    {
        if ($this->exists || ! $this->appending || $this->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Credit ledger entries are append-only and created by ledger operations.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Credit ledger entries are retained as financial evidence.');
    }

    public static function format(int $nanodollars): string
    {
        $magnitude = abs($nanodollars);

        return ($nanodollars < 0 ? '-' : '').sprintf('%d.%09d', intdiv($magnitude, 1_000_000_000), $magnitude % 1_000_000_000);
    }

    /** Whole-cent customer amount for messages (D-054): "$0.45", or "-$0.04" for an overrun balance. */
    public static function formatDollars(int $nanodollars): string
    {
        $cents = intdiv(abs($nanodollars), GenerationPricing::CENT);

        return ($nanodollars < 0 ? '-' : '').sprintf('$%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function record(?int $userId, CreditEntryType $type, int $nanodollars, ?string $generationId = null, ?string $idempotencyKey = null, ?string $reason = null): self
    {
        $entry = new self;
        $entry->appending = true;
        try {
            $entry->user_id = $userId;
            $entry->type = $type;
            $entry->amount_nanodollars = $nanodollars;
            $entry->generation_id = $generationId;
            $entry->idempotency_key = $idempotencyKey;
            $entry->reason = $reason;
            $entry->created_at = now();
            if (! $entry->saveOrFail()) {
                throw new LogicException('Credit ledger entry was not persisted.');
            }
        } finally {
            $entry->appending = false;
        }

        return $entry;
    }

    private static function entryFor(Generation $generation, CreditEntryType $type): ?self
    {
        return self::query()->where('generation_id', $generation->id)->where('type', $type)->first();
    }

    private static function lockOwner(mixed $userId): User
    {
        $owner = User::query()->whereKey($userId)->lockForUpdate()->first();
        if ($owner === null) {
            throw new LogicException('Credit ledger entries require a persisted owner.');
        }

        return $owner;
    }

    /** @param literal-string $signedAmount */
    private static function sumFor(User $user, string $signedAmount): int
    {
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('A credit balance requires a persisted user.');
        }

        return (int) self::query()->where('user_id', $user->getKey())->sum(DB::raw($signedAmount));
    }

    private static function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Credit ledger operations must run inside a database transaction.');
        }
    }
}
