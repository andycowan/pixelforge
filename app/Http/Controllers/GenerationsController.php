<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\Mercure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationsController extends Controller
{
    public function __invoke(Request $request, Mercure $mercure): Response
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:200']])['search'] ?? null;
        $user = $request->user();
        Cookie::queue($mercure->subscriberCookie($user));

        return Inertia::render('Generations', [
            'generations' => Generation::historyFor($user, $search),
            'search' => $search,
            'live' => ['hub' => Mercure::PATH, 'topic' => $mercure->topicFor($user->id)],
        ]);
    }
}
