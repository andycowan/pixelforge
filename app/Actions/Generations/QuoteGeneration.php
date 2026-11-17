<?php

namespace App\Actions\Generations;

use App\Actions\Pixelforge\GetPricingCatalog;
use App\Data\GenerationQuote;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\GenerationPricing;

/**
 * Produce an authoritative price quote for generation options.
 *
 * This is deliberately read-only: a quote never persists a generation,
 * enqueues work or contacts an account-scoped provider endpoint.
 */
final class QuoteGeneration
{
    public function __construct(
        private readonly GetPricingCatalog $catalog,
        private readonly GenerationPricing $pricing,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     */
    public function execute(
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages = [],
    ): GenerationQuote {
        return $this->pricing->quote(
            $prompt,
            $size,
            $quality,
            $referenceImages,
            $this->catalog->get(),
        );
    }
}
