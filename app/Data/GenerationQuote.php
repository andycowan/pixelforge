<?php

namespace App\Data;

use App\Exceptions\PricingException;
use App\Services\GenerationPricing;
use JsonSerializable;

final readonly class GenerationQuote implements JsonSerializable
{
    /**
     * @param  array<string, int>  $tokenEstimate
     * @param  array<string, int>  $tokenMaximum
     */
    public function __construct(
        public int $estimatedCostNanodollars,
        public int $maximumCostNanodollars,
        public int $estimatedProviderCostNanodollars,
        public int $maximumProviderCostNanodollars,
        public array $tokenEstimate,
        public array $tokenMaximum,
        public PixelforgePricing $pricing,
    ) {
        if ($estimatedCostNanodollars < 0 || $estimatedProviderCostNanodollars < 0
            || $maximumCostNanodollars < $estimatedCostNanodollars
            || $maximumProviderCostNanodollars < $estimatedProviderCostNanodollars) {
            throw new PricingException('Invalid quote cost bounds.');
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'rates' => $this->pricing->rates,
            'tokens' => $this->tokenEstimate,
            'bounds' => $this->tokenMaximum,
            'token_rules' => $this->pricing->tokens,
            'policy' => [
                'currency' => 'USD',
                'markup' => '5/4',
                'rounding' => 'ceiling',
                'precision' => 2,
                'estimate_basis' => 'reasoning_midpoint_rounded_up',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => 'usd',
            'estimated_cost_usd' => GenerationPricing::formatUsd($this->estimatedCostNanodollars),
            'maximum_cost_usd' => GenerationPricing::formatUsd($this->maximumCostNanodollars),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
