<?php

use App\Actions\Generations\RequestGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $identity = DB::selectOne('SELECT current_database() AS db, current_user AS role');
    if ($identity->db !== 'pixelforge_test' || $identity->role !== 'pixelforge_test') {
        throw new RuntimeException('Refusing non-isolated request worker.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    config(['services.pixelforge.api_key' => 'workflow-test-key', 'cache.default' => 'array']);
    Queue::fake();
    Saloon::fake([GetPricing::class => MockResponse::make((string) file_get_contents(__DIR__.'/pixelforge-pricing.json'), 200, ['Content-Type' => 'application/json'])]);
    DB::statement("SET application_name = 'pixelforge_credit_race'");
    echo "ready\n";
    flush();
    try {
        $app->make(RequestGeneration::class)->execute(
            User::query()->findOrFail($input['user_id']),
            ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium'],
            $input['token'],
        );
        echo "created\n";
    } catch (ValidationException $exception) {
        echo array_key_first($exception->errors()) === 'credits' ? "insufficient\n" : "invalid\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Request worker failed: '.$exception::class."\n");
    exit(1);
}
