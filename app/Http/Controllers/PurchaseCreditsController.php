<?php

namespace App\Http\Controllers;

use App\Actions\Credits\PurchaseCredits;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Services\GenerationPricing;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class PurchaseCreditsController extends Controller
{
    public function __invoke(PurchaseCreditsRequest $request, PurchaseCredits $purchaseCredits): RedirectResponse
    {
        $validated = $request->validated();
        $result = $purchaseCredits->execute($request->user(), (string) $validated['package'], (string) $validated['idempotency_token']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['created']
                ? sprintf('Added %s of demo credit. No payment was taken.', GenerationPricing::formatDollars($result['entry']->amount_nanodollars))
                : 'This purchase was already applied. Nothing more was added.',
        ]);

        return to_route('credits.index');
    }
}
