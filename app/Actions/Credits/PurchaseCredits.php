<?php

namespace App\Actions\Credits;

use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseCredits
{
    public const PACKAGES = [
        'usd_5' => 5_000_000_000,
        'usd_10' => 10_000_000_000,
        'usd_25' => 25_000_000_000,
    ];

    /** @return list<array{id: string, amount_usd: string}> */
    public static function packages(): array
    {
        return array_map(
            fn (string $id): array => ['id' => $id, 'amount_usd' => GenerationPricing::formatUsd(self::PACKAGES[$id])],
            array_keys(self::PACKAGES),
        );
    }

    /** @return array{entry: CreditLedgerEntry, created: bool} */
    public function execute(User $user, string $package, string $idempotencyKey): array
    {
        $amount = self::PACKAGES[$package] ?? throw ValidationException::withMessages([
            'package' => 'Choose one of the available credit packages.',
        ]);

        return DB::transaction(function () use ($user, $amount, $idempotencyKey): array {
            $entry = CreditLedgerEntry::purchase($user, $amount, $idempotencyKey);
            if ($entry->amount_nanodollars !== $amount) {
                throw ValidationException::withMessages([
                    'idempotency_token' => 'This purchase was already used for a different package. Nothing more was added.',
                ]);
            }

            return ['entry' => $entry, 'created' => $entry->wasRecentlyCreated];
        });
    }
}
