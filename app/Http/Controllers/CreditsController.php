<?php

namespace App\Http\Controllers;

use App\Actions\Credits\PurchaseCredits;
use App\Models\CreditLedgerEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own credit packages and recent ledger activity. The balance
 * itself is a shared prop, so every page shows the same authoritative value.
 */
final class CreditsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Credits', [
            'packages' => PurchaseCredits::packages(),
            'entries' => CreditLedgerEntry::recentFor($request->user()),
        ]);
    }
}
