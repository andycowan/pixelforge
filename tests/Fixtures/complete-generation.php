<?php

use App\Actions\Generations\ApplyProviderGeneration;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../bootstrap.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $context = DB::selectOne('select current_database() as db, current_user as role');
    if ($context->db !== 'pixelforge_test' || $context->role !== 'pixelforge_test') {
        throw new RuntimeException('Refusing non-isolated concurrency worker.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $provider = ProviderGeneration::fromArray($input['provider']);
    DB::statement("SET application_name = 'pixelforge_settlement_race'");
    echo "ready\n";
    flush();
    $outcome = $app->make(ApplyProviderGeneration::class)->execute($input['id'], $provider, $input['fingerprint']);
    echo strtolower($outcome->name)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Concurrency worker failed: '.$exception::class."\n");
    exit(1);
}
