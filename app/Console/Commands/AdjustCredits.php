<?php

namespace App\Console\Commands;

use App\Actions\Credits\AdjustCredits as AdjustCreditsAction;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class AdjustCredits extends Command
{
    protected $signature = 'credits:adjust {email : Email address of the user to adjust}
        {--to= : Target available balance in USD, e.g. 0.40}
        {--reason= : Why the balance is being adjusted (recorded on the ledger)}';

    protected $description = 'Set a user\'s available credit to a target with one auditable ledger adjustment';

    public function handle(AdjustCreditsAction $adjust): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $to = (string) $this->option('to');
        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }
        if (! preg_match('/\A(0|[1-9]\d{0,5})(\.\d{1,2})?\z/D', $to)) {
            $this->error('--to must be a dollar amount with at most two decimal places, e.g. 0.40.');

            return self::FAILURE;
        }
        [$dollars, $cents] = array_pad(explode('.', $to), 2, '0');
        $target = ((int) $dollars * 100 + (int) str_pad($cents, 2, '0')) * 10_000_000;

        try {
            $result = $adjust->toTarget($user, $target, (string) $this->option('reason'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['entry'] === null
            ? 'Available balance is already '.CreditLedgerEntry::formatDollars($result['after']).'; nothing recorded.'
            : sprintf('Available balance %s -> %s (%s %s).', CreditLedgerEntry::formatDollars($result['before']), CreditLedgerEntry::formatDollars($result['after']),
                $result['entry']->type->value, CreditLedgerEntry::formatDollars($result['entry']->amount_nanodollars)));

        return self::SUCCESS;
    }
}
