<?php

namespace App\Actions\Credits;

use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Operator adjustment that sets a user's available balance to a target (D-B16).
 *
 * The difference is computed under the owner lock and recorded as one signed
 * adjustment with its reason. Already at the target: nothing is recorded, so
 * repeating the same command cannot adjust twice.
 */
final class AdjustCredits
{
    /** @return array{before: int, after: int, entry: ?CreditLedgerEntry} */
    public function toTarget(User $user, int $targetNanodollars, string $reason): array
    {
        if ($targetNanodollars < 0 || $targetNanodollars % GenerationPricing::CENT !== 0) {
            throw new InvalidArgumentException('The target balance must be a nonnegative whole-cent amount.');
        }

        return DB::transaction(function () use ($user, $targetNanodollars, $reason): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $before = CreditLedgerEntry::availableFor($user);
            $entry = $before === $targetNanodollars ? null : CreditLedgerEntry::adjust($user, $targetNanodollars - $before, $reason);

            return ['before' => $before, 'after' => CreditLedgerEntry::availableFor($user), 'entry' => $entry];
        });
    }
}
