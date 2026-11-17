<?php

namespace App\Data;

use App\Exceptions\PricingException;
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
                'rounding' => 'half_up',
                'precision' => 9,
                'estimate_basis' => 'reasoning_midpoint_rounded_up',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => 'usd',
            'markup_percent' => 25,
            'estimated_cost_usd' => self::format($this->estimatedCostNanodollars),
            'maximum_cost_usd' => self::format($this->maximumCostNanodollars),
            'provider_estimated_cost_usd' => self::format($this->estimatedProviderCostNanodollars),
            'provider_maximum_cost_usd' => self::format($this->maximumProviderCostNanodollars),
            'token_estimate' => $this->tokenEstimate,
            'token_maximum' => $this->tokenMaximum,
            'rates' => $this->pricing->rates,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function format(int $nanodollars): string
    {
        return sprintf('%d.%09d', intdiv($nanodollars, 1_000_000_000), $nanodollars % 1_000_000_000);
    }
}
