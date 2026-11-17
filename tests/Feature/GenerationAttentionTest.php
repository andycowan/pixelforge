<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\GenerationSubmissionStatus;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerationAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_submission_does_not_claim_another_retry_is_scheduled(): void
    {
        $generation = Generation::factory()->for(User::factory())->create();
        DB::transaction(function () use ($generation): void {
            $generation->prepareSubmission(str_repeat('a', 64));
            $generation->recordSubmissionProblem(['code' => 'overloaded', 'message' => 'Private diagnostic', 'retryable' => false], null, GenerationSubmissionStatus::Pending);
        });

        $message = $generation->toHistoryArray()['submission_message'];
        $this->assertStringContainsString('paused', $message);
        $this->assertStringNotContainsString('waiting to retry', $message);
        $this->assertStringNotContainsString('Private diagnostic', $message);
        $this->assertNull($generation->toHistoryArray()['actual_cost_usd']);
    }

    public function test_stopped_provider_checks_are_visible_without_inventing_a_failure(): void
    {
        $wire = json_decode(file_get_contents(base_path('tests/Fixtures/provider/queued.json')), true, flags: JSON_THROW_ON_ERROR);
        $generation = Generation::factory()->for(User::factory())->create([
            'prompt' => $wire['prompt'], 'size' => $wire['size'], 'quality' => $wire['quality'], 'reference_images' => $wire['reference_images'],
        ]);
        $fingerprint = str_repeat('a', 64);
        DB::transaction(fn () => $generation->prepareSubmission($fingerprint));
        $wire['metadata'] = ['generation_id' => $generation->id, 'key_fingerprint' => $fingerprint];
        app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($wire), $fingerprint);
        DB::transaction(function () use ($generation): void {
            $fresh = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $fresh->deferProviderCheck(null);
        });

        $history = $generation->fresh()->toHistoryArray();
        $this->assertSame('queued', $history['provider_status']);
        $this->assertStringContainsString('paused', $history['submission_message']);
        $this->assertNull($history['actual_cost_usd']);
    }
}
