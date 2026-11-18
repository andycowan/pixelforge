<?php

namespace App\Actions\Generations;

use App\Actions\Pixelforge\GetPricingCatalog;
use App\Data\GenerationQuote;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Services\GenerationPricing;

final class QuoteGeneration
{
    public function __construct(
        private readonly GetPricingCatalog $catalog,
        private readonly GenerationPricing $pricing,
    ) {}

    /** @param  list<string>  $referenceImages */
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
