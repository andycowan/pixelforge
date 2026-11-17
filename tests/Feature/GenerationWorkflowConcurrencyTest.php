<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Models\Generation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GenerationWorkflowFixture;
use Tests\TestCase;

final class GenerationWorkflowConcurrencyTest extends TestCase
{
    use DatabaseMigrations, GenerationWorkflowFixture;

    #[DataProvider('workTypes')]
    public function test_independent_workers_claim_one_network_operation_and_commit_before_http(string $mode): void
    {
        $this->travelTo(now()->startOfSecond());
        $generation = Generation::factory()->create();
        DB::transaction(fn () => $generation->prepareSubmission(hash('sha256', 'workflow-test-key')));
        if ($mode === 'reconcile') {
            app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($this->providerPayload($generation)), hash('sha256', 'workflow-test-key'));
        }
        $payload = $this->providerPayload($generation, $mode === 'submit' ? 'queued' : 'processing');
        $this->travel(15)->seconds();
        $input = json_encode(['mode' => $mode, 'id' => $generation->id, 'provider' => $payload, 'clock' => now()->toIso8601String()], JSON_THROW_ON_ERROR);
        $workers = [];
        DB::beginTransaction();
        try {
            Generation::query()->lockForUpdate()->findOrFail($generation->id);
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/run-generation-work.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], $input);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            $deadline = microtime(true) + 5;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE datname = 'pixelforge_test' AND usename = 'pixelforge_test' AND application_name = 'pixelforge_workflow_race' AND wait_event_type = 'Lock'");
                if ((int) $waiting->count === 2) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, (int) $waiting->count, 'Both workers must contend concurrently, not run sequentially.');
            DB::commit();
            $output = '';
            foreach ($workers as [$process, $pipes]) {
                $output .= stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $diagnostic = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $diagnostic);
            }
            $workers = [];
            $this->assertSame(1, substr_count($output, "http\n"));
            $this->assertSame(2, substr_count($output, "done\n"));
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
        $fresh = $generation->fresh();
        $this->assertSame($mode === 'submit' ? 1 : 0, $fresh->submission_attempts);
        $this->assertSame($mode === 'submit' ? 'queued' : 'processing', $fresh->provider_status->value);
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('generation_charges', 0);
    }

    public static function workTypes(): array
    {
        return [['submit'], ['reconcile']];
    }
}
