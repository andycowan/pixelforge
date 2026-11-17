<?php

namespace App\Models;

use App\Enums\ProviderGenerationStatus;
use App\Exceptions\ProviderEvidenceConflict;
use App\Services\GenerationPricing;
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
        $customerCost = $pricing->customerCost($providerCost);
        $existing = self::query()->find($generation->id);
        if ($existing !== null) {
            if ($existing->provider_cost_nanodollars !== $providerCost || $existing->customer_cost_nanodollars !== $customerCost
                || $existing->markup_numerator !== 5 || $existing->markup_denominator !== 4
                || $existing->rounding !== 'ceiling' || $existing->scale !== 2
                || ! $existing->provider_completed_at->equalTo($generation->provider_completed_at)) {
                throw new ProviderEvidenceConflict('Existing settlement does not agree with provider evidence.');
            }

            return $existing;
        }
        $charge = new self;
        $charge->settling = true;
        try {
            $charge->generation_id = $generation->id;
            $charge->provider_cost_nanodollars = $providerCost;
            $charge->customer_cost_nanodollars = $customerCost;
            $charge->markup_numerator = 5;
            $charge->markup_denominator = 4;
            $charge->rounding = 'ceiling';
            $charge->scale = 2;
            $charge->provider_completed_at = $generation->provider_completed_at;
            $charge->created_at = now();
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

    public function customerCostUsd(): string
    {
        return sprintf('%d.%09d', intdiv($this->customer_cost_nanodollars, 1_000_000_000), $this->customer_cost_nanodollars % 1_000_000_000);
    }
}
