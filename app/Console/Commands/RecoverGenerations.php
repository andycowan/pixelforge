<?php

namespace App\Console\Commands;

use App\Actions\Generations\RecoverGenerationWork;
use Illuminate\Console\Command;

final class RecoverGenerations extends Command
{
    protected $signature = 'generations:recover';

    protected $description = 'Redispatch up to twenty due durable submission, inbox and reconciliation items';

    public function handle(RecoverGenerationWork $recover): int
    {
        $this->info('Dispatched '.$recover->execute().' durable generation work items.');

        return self::SUCCESS;
    }
}
