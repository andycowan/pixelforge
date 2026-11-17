<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Actions\Generations\RecoverGenerationWork;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetGeneration;
use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\PublishOutboxJob;
use App\Jobs\ReconcileGenerationJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\GenerationWorkflowFixture;
use Tests\TestCase;

final class ReconciliationRecoveryTest extends TestCase
{
    use GenerationWorkflowFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => 'workflow-test-key']);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_reconciliation_claims_lease_and_duplicate_job_does_not_get_again_for_15_seconds(): void
    {
        $generation = $this->accepted();
        $payload = $this->providerPayload($generation, 'processing');
        Saloon::fake([GetGeneration::class => function () use ($generation, $payload) {
            $this->assertTrue($generation->fresh()->next_attempt_at->isFuture());
            $this->assertSame(0, $generation->fresh()->submission_attempts);

            return MockResponse::make($payload);
        }]);
        $this->travel(15)->seconds();
        $this->check($generation);
        $this->check($generation);
        Saloon::assertSentCount(1);
        $this->assertSame('processing', $generation->fresh()->provider_status->value);
        $this->assertSame(15, (int) now()->diffInSeconds($generation->fresh()->next_attempt_at));
    }

    public function test_reconciliation_uses_shared_apply_and_never_polls_completed_images(): void
    {
        $generation = $this->accepted();
        $payload = $this->providerPayload($generation, 'succeeded');
        Saloon::fake([GetGeneration::class => MockResponse::make($payload)]);
        $this->travel(15)->seconds();
        $this->check($generation);
        $this->check($generation);
        $this->travel(2)->days();
        $this->check($generation);
        Saloon::assertSentCount(1);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertNull($generation->fresh()->next_attempt_at);
    }

    public function test_automatic_window_stops_without_claiming_failure(): void
    {
        $generation = $this->accepted();
        Saloon::fake([]);
        $this->travel(30)->minutes();
        $this->check($generation);
        Saloon::assertNothingSent();
        $this->assertSame('queued', $generation->fresh()->provider_status->value);
        $this->assertNull($generation->fresh()->next_attempt_at);
        $this->assertDatabaseCount('generation_charges', 0);
    }

    public function test_key_rotation_stops_retrieval_and_does_not_rebind(): void
    {
        $generation = $this->accepted();
        config(['services.pixelforge.api_key' => 'rotated-key']);
        Saloon::fake([]);
        $this->travel(15)->seconds();
        $this->check($generation);
        Saloon::assertNothingSent();
        $this->assertSame(hash('sha256', 'workflow-test-key'), $generation->fresh()->provider_key_fingerprint);
        $this->assertNull($generation->fresh()->next_attempt_at);
    }

    public function test_retrieval_rate_limit_honours_retry_after_without_provider_failure(): void
    {
        $generation = $this->accepted();
        Saloon::fake([GetGeneration::class => MockResponse::make(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
        $this->travel(15)->seconds();
        $this->check($generation);
        $this->assertSame(120, (int) now()->diffInSeconds($generation->fresh()->next_attempt_at));
        $this->assertSame('queued', $generation->fresh()->provider_status->value);
        $this->assertSame(0, $generation->fresh()->submission_attempts);
    }

    public function test_authentication_and_malformed_retrieval_stop_for_attention_not_failed(): void
    {
        foreach ([MockResponse::make(['error' => ['code' => 'invalid_api_key']], 401), MockResponse::make(['object' => 'bad'])] as $response) {
            $generation = $this->accepted();
            Saloon::fake([GetGeneration::class => $response]);
            $this->travel(15)->seconds();
            $this->check($generation);
            $this->assertSame('queued', $generation->fresh()->provider_status->value);
            $this->assertNull($generation->fresh()->next_attempt_at);
            $this->assertDatabaseCount('generation_charges', 0);
        }
    }

    public function test_recovery_total_batch_is_twenty_and_all_three_work_types_get_a_turn(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->pending();
            $this->accepted();
            $this->inbox('evt_recovery'.$i);
        }
        $this->travel(15)->seconds();
        Queue::fake(); // Count only what recovery dispatches, not the fixtures' own notifications.
        $this->artisan('generations:recover')->expectsOutput('Dispatched 20 durable generation work items.')->assertSuccessful();
        Queue::assertPushed(ProcessWebhookEventJob::class, 7);
        Queue::assertPushed(SubmitGenerationJob::class, 7);
        Queue::assertPushed(ReconcileGenerationJob::class, 6);
        // Plus one publisher for the accepted fixtures' unpublished notification intent.
        Queue::assertPushed(PublishOutboxJob::class, 1);
        $this->assertSame(21, Queue::size());
    }

    public function test_recovery_rescues_crashed_submission_after_lease_but_not_before(): void
    {
        $generation = $this->pending();
        DB::transaction(fn () => $generation->claimSubmissionAttempt(now()->addSeconds(60)));
        $this->assertSame(0, app(RecoverGenerationWork::class)->execute());
        $this->travel(61)->seconds();
        $this->assertSame(1, app(RecoverGenerationWork::class)->execute());
        Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $generation->id);
        $this->assertSame(1, $generation->fresh()->submission_attempts);
    }

    public function test_recovery_does_not_redispatch_processed_inbox_or_operator_blocked_submissions(): void
    {
        $generation = $this->pending();
        DB::transaction(fn () => $generation->recordSubmissionProblem(['code' => 'invalid_api_key', 'message' => 'Attention required.', 'retryable' => false], null, $generation->submission_status));
        $event = $this->inbox('evt_processed');
        DB::transaction(fn () => $event->markProcessed('ignored'));
        $this->assertSame(0, app(RecoverGenerationWork::class)->execute());
        Queue::assertNothingPushed();
    }

    private function pending(): Generation
    {
        $generation = Generation::factory()->create();
        DB::transaction(fn () => $generation->prepareSubmission(hash('sha256', 'workflow-test-key')));

        return $generation;
    }

    private function accepted(): Generation
    {
        $generation = $this->pending();
        app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($this->providerPayload($generation)), hash('sha256', 'workflow-test-key'));

        return $generation->fresh();
    }

    private function inbox(string $id): WebhookEvent
    {
        return WebhookEvent::query()->create(['id' => $id, 'payload' => ['id' => $id, 'type' => 'ping'], 'provider_key_fingerprint' => hash('sha256', 'workflow-test-key'), 'received_at' => now()]);
    }

    private function check(Generation $generation): void
    {
        app()->call([new ReconcileGenerationJob($generation->id), 'handle']);
    }
}
