<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\GenerationSubmissionStatus;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Http\Integrations\Pixelforge\Requests\CreateGeneration;
use App\Jobs\Middleware\ProviderRequestBudget;
use App\Jobs\PublishOutboxJob;
use App\Jobs\ReconcileGenerationJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\GenerationWorkflowFixture;
use Tests\TestCase;

final class SubmissionWorkflowTest extends TestCase
{
    use GenerationWorkflowFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => 'workflow-test-key']);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_claim_is_durable_before_post_and_duplicate_job_does_not_repeat_network(): void
    {
        $generation = $this->generation();
        $payload = $this->providerPayload($generation);
        Saloon::fake([CreateGeneration::class => function () use ($generation, $payload) {
            $persisted = $generation->fresh();
            $this->assertSame(GenerationSubmissionStatus::Uncertain, $persisted->submission_status);
            $this->assertSame(1, $persisted->submission_attempts);
            $this->assertSame(hash('sha256', 'workflow-test-key'), $persisted->provider_key_fingerprint);
            $this->assertTrue($persisted->next_attempt_at->isFuture());

            return MockResponse::make($payload, 202);
        }]);
        $this->submit($generation);
        $this->submit($generation);
        Saloon::assertSentCount(1);
        $this->assertSame(GenerationSubmissionStatus::Accepted, $generation->fresh()->submission_status);
        Queue::assertPushed(ReconcileGenerationJob::class, 1);
        Queue::assertPushed(PublishOutboxJob::class, 1);
        $this->assertDatabaseCount('generation_charges', 0);
    }

    public function test_timeout_then_retry_reuses_exact_idempotency_payload_and_frozen_metadata(): void
    {
        $generation = $this->generation();
        $requests = [];
        Saloon::fake([CreateGeneration::class => function (PendingRequest $pending) use ($generation, &$requests) {
            $request = $pending->getRequest();
            $requests[] = [$request->headers()->get('Idempotency-Key'), $request->body()->all()];
            if (count($requests) === 1) {
                return $this->timeout();
            }

            return MockResponse::make($this->providerPayload($generation, 'succeeded'), 202);
        }]);
        $this->submit($generation);
        $this->assertSame('uncertain', $generation->fresh()->submission_status->value);
        $this->travel(20)->seconds();
        $this->submit($generation);
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertSame($generation->id, $requests[0][0]);
        $this->assertSame(['generation_id' => $generation->id, 'key_fingerprint' => hash('sha256', 'workflow-test-key')], $requests[0][1]['metadata']);
        $this->assertSame('succeeded', $generation->fresh()->provider_status->value);
        $this->assertDatabaseCount('generation_charges', 1);
    }

    #[DataProvider('blockingErrors')]
    public function test_known_error_cannot_disprove_prior_acceptance(string $code, int $status): void
    {
        $generation = $this->generation();
        Saloon::fake([$this->timeout(), MockResponse::make(['error' => ['code' => $code, 'message' => 'Never expose arbitrary provider text']], $status)]);
        $this->submit($generation);
        $this->travel(20)->seconds();
        $this->submit($generation);
        $fresh = $generation->fresh();
        $this->assertSame(GenerationSubmissionStatus::Uncertain, $fresh->submission_status);
        $this->assertNull($fresh->provider_status);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertFalse($fresh->submission_error['retryable']);
        $this->assertStringNotContainsString('Never expose', $fresh->submission_error['message']);
        $this->assertDatabaseCount('generation_charges', 0);
    }

    public static function blockingErrors(): array
    {
        return [['content_policy_violation', 400], ['validation_failed', 422], ['invalid_api_key', 401], ['daily_limit_exceeded', 429], ['idempotency_key_reused', 409]];
    }

    public function test_first_definitive_content_rejection_is_not_provider_failure(): void
    {
        $generation = $this->generation();
        Saloon::fake([CreateGeneration::class => MockResponse::make(['error' => ['code' => 'content_policy_violation']], 400)]);
        $this->submit($generation);
        $this->assertSame(GenerationSubmissionStatus::Rejected, $generation->fresh()->submission_status);
        $this->assertNull($generation->fresh()->provider_status);
        $this->assertDatabaseCount('generation_charges', 0);
        // A rejection is a visible change: it must reach the live page too.
        $this->assertDatabaseHas('outbox_messages', ['generation_id' => $generation->id, 'revision' => $generation->fresh()->revision]);
        Queue::assertPushed(PublishOutboxJob::class, 1);
    }

    public function test_network_attempt_budget_is_five_and_exhaustion_is_not_false_failure(): void
    {
        $generation = $this->generation();
        $calls = 0;
        Saloon::fake([CreateGeneration::class => function () use (&$calls) {
            $calls++;

            return $this->timeout();
        }]);
        for ($i = 0; $i < 8; $i++) {
            $this->submit($generation);
            $this->travel(5)->minutes();
        }
        $this->assertSame(5, $calls);
        $fresh = $generation->fresh();
        $this->assertSame(5, $fresh->submission_attempts);
        $this->assertSame(GenerationSubmissionStatus::Uncertain, $fresh->submission_status);
        $this->assertNull($fresh->provider_status);
        $this->assertNull($fresh->next_attempt_at);
        $this->assertFalse($fresh->submission_error['retryable']);
    }

    public function test_retry_after_is_respected_without_burning_attempts_on_early_duplicate(): void
    {
        $generation = $this->generation();
        Saloon::fake([CreateGeneration::class => MockResponse::make(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
        $this->submit($generation);
        $this->assertSame(GenerationSubmissionStatus::Pending, $generation->fresh()->submission_status);
        $this->assertGreaterThanOrEqual(120, now()->diffInSeconds($generation->fresh()->next_attempt_at));
        $this->travel(60)->seconds();
        $this->submit($generation);
        Saloon::assertSentCount(1);
        $this->assertSame(1, $generation->fresh()->submission_attempts);
    }

    public function test_early_webhook_acceptance_wins_over_late_create_error(): void
    {
        $generation = $this->generation();
        Saloon::fake([CreateGeneration::class => function () use ($generation) {
            app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($this->providerPayload($generation, 'succeeded')), hash('sha256', 'workflow-test-key'));

            return MockResponse::make(['error' => ['code' => 'overloaded']], 503);
        }]);
        $this->submit($generation);
        $this->assertSame(GenerationSubmissionStatus::Accepted, $generation->fresh()->submission_status);
        $this->assertSame('succeeded', $generation->fresh()->provider_status->value);
        $this->assertNull($generation->fresh()->next_attempt_at);
        $this->assertDatabaseCount('generation_charges', 1);
    }

    public function test_rotated_or_missing_runtime_key_never_rebinds_or_calls_provider(): void
    {
        Saloon::fake([]);
        foreach (['rotated-key', ''] as $key) {
            $generation = $this->generation();
            config(['services.pixelforge.api_key' => $key]);
            $this->submit($generation);
            $fresh = $generation->fresh();
            $this->assertSame(hash('sha256', 'workflow-test-key'), $fresh->provider_key_fingerprint);
            $this->assertSame(0, $fresh->submission_attempts);
            $this->assertFalse($fresh->submission_error['retryable']);
        }
        Saloon::assertNothingSent();
    }

    public function test_budget_middleware_releases_before_network_attempt_and_is_global(): void
    {
        RateLimiter::clear('pixelforge:provider-budget');
        $generation = $this->generation();
        $job = new class
        {
            public array $releases = [];

            public function release(int $delay): void
            {
                $this->releases[] = $delay;
            }
        };
        $calls = 0;
        $middleware = new ProviderRequestBudget;
        for ($i = 0; $i < 50; $i++) {
            $middleware->handle($job, function () use (&$calls): void {
                $calls++;
            });
        }
        $this->assertSame(45, $calls);
        $this->assertCount(5, $job->releases);
        $this->assertSame(0, $generation->fresh()->submission_attempts);
        $this->travel(61)->seconds();
        $middleware->handle($job, function () use (&$calls): void {
            $calls++;
        });
        $this->assertSame(46, $calls);
    }

    public function test_jobs_serialize_only_ids_and_never_runtime_credentials(): void
    {
        $generation = $this->generation();
        foreach ([new SubmitGenerationJob($generation->id), new ReconcileGenerationJob($generation->id)] as $job) {
            $serialized = serialize($job);
            $this->assertStringContainsString($generation->id, $serialized);
            $this->assertStringNotContainsString('workflow-test-key', $serialized);
            $this->assertStringNotContainsString($generation->prompt, $serialized);
        }
    }

    private function generation(): Generation
    {
        $generation = Generation::factory()->create();
        DB::transaction(fn () => $generation->prepareSubmission(hash('sha256', 'workflow-test-key')));

        return $generation;
    }

    private function submit(Generation $generation): void
    {
        app()->call([new SubmitGenerationJob($generation->id), 'handle']);
    }

    private function timeout(): MockResponse
    {
        return MockResponse::make()->throw(fn (PendingRequest $pending) => new FatalRequestException(new \RuntimeException('simulated timeout'), $pending));
    }
}
