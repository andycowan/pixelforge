<?php

namespace App\Http\Controllers;

use App\Actions\Generations\RequestGeneration;
use App\Exceptions\PricingException;
use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Validated input -> request action -> redirect to the owned history.
 *
 * The controller never quotes, prices, dispatches or reads generation rows;
 * those decisions belong to the action and the model.
 */
final class StoreGenerationController extends Controller
{
    public function __invoke(
        StoreGenerationRequest $request,
        RequestGeneration $requestGeneration,
    ): RedirectResponse {
        $validated = $request->validated();

        /** @var list<string> $referenceImages */
        $referenceImages = $validated['reference_images'] ?? [];

        try {
            $requestGeneration->execute(
                $request->user(),
                [
                    'prompt' => $validated['prompt'],
                    'size' => $validated['size'],
                    'quality' => $validated['quality'],
                    'reference_images' => $referenceImages,
                ],
                (string) $validated['submission_token'],
            );
        } catch (PricingException $exception) {
            report($exception);

            throw new ServiceUnavailableHttpException(
                null,
                'Pricing is temporarily unavailable. Nothing was created or charged. Please try again shortly.',
            );
        }

        return to_route('generations.index');
    }
}
