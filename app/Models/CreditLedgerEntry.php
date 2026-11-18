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
    public const MAX_PURCHASE_NANODOLLARS = 100 * 1_000_000_000;

    public const MAX_ADJUSTMENT_NANODOLLARS = 1_000 * 1_000_000_000;

    private const AVAILABLE_SQL = "CASE WHEN type IN ('purchase', 'release', 'credit_adjustment') THEN amount_nanodollars ELSE -amount_nanodollars END";

    private const HELD_SQL = "CASE type WHEN 'hold' THEN amount_nanodollars WHEN 'release' THEN -amount_nanodollars ELSE 0 END";

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

    public static function availableFor(User $user): int
    {
        // shortcut: sums the user's whole ledger on every read; add a periodic balance snapshot if per-user entries grow into the hundreds of thousands.
        return self::sumFor($user, self::AVAILABLE_SQL);
    }

    public static function heldFor(User $user): int
    {
        return self::sumFor($user, self::HELD_SQL);
    }

    /** @return array{available: int, held: int} */
    public static function bank(): array
    {
        $row = self::query()->selectRaw('COALESCE(SUM('.self::AVAILABLE_SQL.'), 0) AS available, COALESCE(SUM('.self::HELD_SQL.'), 0) AS held')->toBase()->first();

        return ['available' => (int) ($row->available ?? 0), 'held' => (int) ($row->held ?? 0)];
    }

    /** @return array{available_usd: string, held_usd: string} */
    public static function balanceFor(User $user): array
    {
        return [
            'available_usd' => GenerationPricing::formatUsd(self::availableFor($user)),
            'held_usd' => GenerationPricing::formatUsd(self::heldFor($user)),
        ];
    }

    public static function canAfford(User $user, int $nanodollars): bool
    {
        return self::availableFor($user) >= $nanodollars;
    }

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

    /** @throws InsufficientCredit */
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

    public static function settle(Generation $generation, GenerationCharge $charge): ?self
    {
        self::assertTransaction();
        if ($charge->generation_id !== $generation->id) {
            throw new LogicException('A debit must settle its own generation charge.');
        }
        $hold = self::entryFor($generation, CreditEntryType::Hold);
        if ($hold === null) {
            return null;
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

    /** @return array<int, array<string, mixed>> */
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
            'amount_usd' => GenerationPricing::formatUsd($this->amount_nanodollars),
            'generation_id' => $this->generation_id,
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
