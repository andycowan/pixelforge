<?php

namespace App\Http\Controllers;

use App\Actions\Generations\QuoteGeneration;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\PricingException;
use App\Http\Requests\GenerationQuoteRequest;
use Illuminate\Http\JsonResponse;

/**
 * JSON quote endpoint: validated input -> action -> response.
 */
final class GenerationQuoteController extends Controller
{
    public function __invoke(GenerationQuoteRequest $request, QuoteGeneration $quoteGeneration): JsonResponse
    {
        $validated = $request->validated();

        /** @var list<string> $referenceImages */
        $referenceImages = $validated['reference_images'] ?? [];

        try {
            $quote = $quoteGeneration->execute(
                prompt: (string) $validated['prompt'],
                size: GenerationSize::from((string) $validated['size']),
                quality: GenerationQuality::from((string) $validated['quality']),
                referenceImages: $referenceImages,
            );
        } catch (PricingException $exception) {
            report($exception);

            return $this->noStore(
                response()->json(['message' => 'Pricing is temporarily unavailable. Please try again shortly.'], 503)
            );
        }

        return $this->noStore(response()->json($quote->toArray()));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'private, no-store');
    }
}
