<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreditHoldConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_submissions_with_credit_for_one_leave_exactly_one_hold(): void
    {
        $context = DB::selectOne('select current_database() as db, current_user as role');
        $this->assertSame('pixelforge_test', $context->db);
        $this->assertSame('pixelforge_test', $context->role);
        $user = User::factory()->withCredit(90_000_000)->create();
        $workers = [];
        DB::beginTransaction();
        try {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/request-generation.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode(['user_id' => $user->id, 'token' => (string) Str::uuid7()], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame("ready\n", fgets($pipes[1]), 'Worker must bootstrap its independent test connection.');
            }
            $deadline = microtime(true) + 5;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where datname = 'pixelforge_test' and usename = 'pixelforge_test' and application_name = 'pixelforge_credit_race' and wait_event_type = 'Lock'");
                if ((int) $waiting->count === 2) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, (int) $waiting->count, 'Both submissions must contend on the owner lock concurrently.');
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
            $this->assertEqualsCanonicalizing(['created', 'insufficient'], $outcomes);
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
        $this->assertDatabaseCount('generations', 1);
        $this->assertSame(1, CreditLedgerEntry::query()->where('type', 'hold')->count());
        $this->assertSame(0, CreditLedgerEntry::availableFor($user));
    }
}
