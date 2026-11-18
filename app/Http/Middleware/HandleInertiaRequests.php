<?php

namespace App\Http\Middleware;

use App\Models\CreditLedgerEntry;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'credits' => fn (): ?array => $request->user() === null ? null : CreditLedgerEntry::balanceFor($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
