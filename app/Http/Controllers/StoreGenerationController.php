<?php

namespace App\Http\Controllers;

use App\Actions\Generations\RequestGeneration;
use App\Exceptions\PricingException;
use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class StoreGenerationController extends Controller
{
    public function __invoke(
        StoreGenerationRequest $request,
        RequestGeneration $requestGeneration,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $requestGeneration->execute($request->user(), $validated, (string) $validated['submission_token']);
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
