<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class SetAdmin extends Command
{
    protected $signature = 'users:admin {email : Email address of the user}
        {--revoke : Remove operator access instead of granting it}';

    protected $description = 'Grant or revoke operator (admin report) access for a user';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }
        // is_admin is deliberately not mass assignable.
        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info($user->is_admin ? "{$user->email} is now an operator." : "{$user->email} is no longer an operator.");

        return self::SUCCESS;
    }
}
