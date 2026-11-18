<?php

namespace App\Http\Controllers;

use App\Actions\Credits\PurchaseCredits;
use App\Models\CreditLedgerEntry;
use App\Services\Mercure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

final class CreditsController extends Controller
{
    public function __invoke(Request $request, Mercure $mercure): Response
    {
        $user = $request->user();
        Cookie::queue($mercure->subscriberCookie($user));

        return Inertia::render('Credits', [
            'packages' => PurchaseCredits::packages(),
            'entries' => CreditLedgerEntry::recentFor($user),
            'live' => ['hub' => Mercure::PATH, 'topic' => $mercure->topicFor($user->id)],
        ]);
    }
}
