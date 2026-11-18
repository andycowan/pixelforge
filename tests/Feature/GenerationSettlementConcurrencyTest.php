<?php

namespace Tests\Feature;

use App\Models\Generation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fixtures\GenerationEvidence;
use Tests\TestCase;

final class GenerationSettlementConcurrencyTest extends TestCase
{
    use DatabaseMigrations, GenerationEvidence;

    public function test_two_independent_postgres_processes_complete_one_committed_generation_once(): void
    {
        $context = DB::selectOne('select current_database() as db, current_user as role');
        $this->assertSame('pixelforge_test', $context->db);
        $this->assertSame('pixelforge_test', $context->role);
        $this->assertSame(0, DB::transactionLevel());
        $generation = $this->preparedGeneration();
        $input = json_encode(['id' => $generation->id, 'fingerprint' => $this->fingerprint(), 'provider' => $this->evidence($generation)->toArray()], JSON_THROW_ON_ERROR);
        $workers = [];
        DB::beginTransaction();
        try {
            Generation::query()->lockForUpdate()->findOrFail($generation->id);
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/complete-generation.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], $input);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame("ready\n", fgets($pipes[1]), 'Worker must bootstrap its independent test connection.');
            }
            $deadline = microtime(true) + 5;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where datname = 'pixelforge_test' and usename = 'pixelforge_test' and application_name = 'pixelforge_settlement_race' and wait_event_type = 'Lock'");
                if ((int) $waiting->count === 2) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, (int) $waiting->count, 'Both completion processes must contend on the row concurrently.');
            $this->assertDatabaseCount('generation_charges', 0);
            DB::commit();
            $outcomes = [];
            foreach ($workers as [$process, $pipes]) {
                $outcomes[] = trim((string) fgets($pipes[1]));
                fclose($pipes[1]);
                $diagnostic = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $diagnostic);
            }
            $workers = [];
            $this->assertEqualsCanonicalizing(['advance', 'duplicate'], $outcomes);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
        $this->assertSame(1, $generation->fresh()->revision);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_named_mutation_outside_transaction_fails_closed(): void
    {
        $generation = $this->preparedGeneration();
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);
        $generation->claimSubmissionAttempt(now()->addMinute());
    }
}
