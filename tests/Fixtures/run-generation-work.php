<?php

use App\Http\Integrations\Pixelforge\Requests\CreateGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetGeneration;
use App\Jobs\ReconcileGenerationJob;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $identity = DB::selectOne('SELECT current_database() AS db, current_user AS role');
    if ($identity->db !== 'pixelforge_test' || $identity->role !== 'pixelforge_test') {
        throw new RuntimeException('Refusing non-isolated workflow worker.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    Carbon::setTestNow($input['clock']);
    config(['services.pixelforge.api_key' => 'workflow-test-key']);
    Queue::fake();
    $response = function () use ($input): MockResponse {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider HTTP must not run in a database transaction.');
        }
        echo "http\n";
        flush();
        usleep(100_000);

        return MockResponse::make($input['provider'], 202);
    };
    Saloon::fake([CreateGeneration::class => $response, GetGeneration::class => $response]);
    DB::statement("SET application_name = 'pixelforge_workflow_race'");
    echo "ready\n";
    flush();
    $job = $input['mode'] === 'submit' ? new SubmitGenerationJob($input['id']) : new ReconcileGenerationJob($input['id']);
    $app->call([$job, 'handle']);
    echo "done\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Workflow worker failed: '.$exception::class."\n");
    exit(1);
}
