<?php

namespace App\Models;

use App\Data\PixelforgePricing;
use App\Enums\ProviderGenerationStatus;
use App\Exceptions\ProviderEvidenceConflict;
use App\Services\GenerationPricing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $generation_id
 * @property int $provider_cost_nanodollars
 * @property int $customer_cost_nanodollars
 * @property int $markup_numerator
 * @property int $markup_denominator
 * @property string $rounding
 * @property int $scale
 * @property Carbon $provider_completed_at
 * @property CarbonInterface $created_at
 */
final class GenerationCharge extends Model
{
    private const POLICY = ['markup_numerator' => 5, 'markup_denominator' => 4, 'rounding' => 'ceiling', 'scale' => 2];

    protected $primaryKey = 'generation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    private bool $settling = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_cost_nanodollars' => 'integer',
            'customer_cost_nanodollars' => 'integer',
            'markup_numerator' => 'integer',
            'markup_denominator' => 'integer',
            'scale' => 'integer',
            'provider_completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    public static function ensureFor(Generation $generation, GenerationPricing $pricing): self
    {
        if (! $generation->exists || $generation->getConnection()->transactionLevel() < 1
            || $generation->provider_status !== ProviderGenerationStatus::Succeeded
            || $generation->usage === null || $generation->rates_snapshot === null || $generation->provider_completed_at === null) {
            throw new LogicException('Settlement requires locked successful provider evidence in a transaction.');
        }
        $providerCost = $pricing->providerCost($generation->usage, $generation->rates_snapshot);
        $settlement = [
            ...self::POLICY,
            'provider_cost_nanodollars' => $providerCost,
            'customer_cost_nanodollars' => $pricing->customerCost($providerCost),
        ];
        $existing = self::query()->find($generation->id);
        if ($existing !== null) {
            if ($existing->only(array_keys($settlement)) !== $settlement
                || ! $existing->provider_completed_at->equalTo($generation->provider_completed_at)) {
                throw new ProviderEvidenceConflict('Existing settlement does not agree with provider evidence.');
            }

            return $existing;
        }
        $charge = new self;
        $charge->settling = true;
        try {
            $charge->forceFill([
                ...$settlement,
                'generation_id' => $generation->id,
                'provider_completed_at' => $generation->provider_completed_at,
                'created_at' => now(),
            ]);
            if (! $charge->saveOrFail()) {
                throw new LogicException('Generation settlement was not persisted.');
            }
        } finally {
            $charge->settling = false;
        }

        return $charge;
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = [])
    {
        if ($this->exists || ! $this->settling || $this->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Generation charges are immutable and created only by settlement.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Generation charges are retained as immutable evidence.');
    }

    /** @return list<array{date: string, user_id: int|null, generations: int, customer: int, provider: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}> */
    public static function dailyTotalsByUser(CarbonImmutable $start, CarbonImmutable $end, string $keyFingerprint): array
    {
        $tokens = implode(', ', array_map(fn (string $type): string => "sum((g.usage->>'{$type}')::bigint) AS {$type}", PixelforgePricing::TOKEN_TYPES));

        return array_values(self::query()->from('generation_charges AS c')->join('generations AS g', 'g.id', '=', 'c.generation_id')
            ->where('g.provider_key_fingerprint', $keyFingerprint)
            ->where('c.provider_completed_at', '>=', $start->startOfDay())
            ->where('c.provider_completed_at', '<', $end->addDay()->startOfDay())
            ->selectRaw("to_char(c.provider_completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS date, g.user_id, count(*) AS generations, sum(c.customer_cost_nanodollars) AS customer, sum(c.provider_cost_nanodollars) AS provider, {$tokens}")
            ->groupBy('date', 'g.user_id')->orderBy('date')->orderBy('g.user_id')
            ->toBase()->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->date,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'generations' => (int) $row->generations,
                'customer' => (int) $row->customer,
                'provider' => (int) $row->provider,
                'input_text_tokens' => (int) $row->input_text_tokens,
                'input_image_tokens' => (int) $row->input_image_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'reasoning_tokens' => (int) $row->reasoning_tokens,
            ])->all());
    }

    public static function otherKeyCountBetween(CarbonImmutable $start, CarbonImmutable $end, ?string $keyFingerprint): int
    {
        return self::query()->join('generations', 'generations.id', '=', 'generation_charges.generation_id')
            ->where('generation_charges.provider_completed_at', '>=', $start->startOfDay())
            ->where('generation_charges.provider_completed_at', '<', $end->addDay()->startOfDay())
            ->when($keyFingerprint !== null, fn ($query) => $query->where('generations.provider_key_fingerprint', '!=', $keyFingerprint))
            ->count();
    }

    public function customerCostUsd(): string
    {
        return GenerationPricing::formatUsd($this->customer_cost_nanodollars);
    }
}
