<?php

namespace Tests\Feature;

use App\Http\Controllers\PixelforgeWebhookController;
use App\Jobs\ProcessWebhookEventJob;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Support\GenerationWorkflowFixture;
use Tests\TestCase;

final class WebhookWorkflowTest extends TestCase
{
    use GenerationWorkflowFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.api_key' => 'workflow-test-key', 'services.pixelforge.webhook_secret' => 'workflow-test-secret']);
        Route::post('/_test/pixelforge-webhook', PixelforgeWebhookController::class);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_receipt_is_durable_and_duplicate_cannot_replace_evidence(): void
    {
        $payload = $this->ping();
        $this->receive($payload)->assertNoContent();
        $this->receive($payload)->assertNoContent();
        $this->assertDatabaseCount('webhook_events', 1);
        $event = WebhookEvent::query()->sole();
        $this->assertEquals($payload, $event->payload);
        $this->assertSame(hash('sha256', 'workflow-test-key'), $event->provider_key_fingerprint);
        $changed = array_replace($payload, ['type' => 'unknown.event']);
        $this->receive($changed)->assertStatus(409);
        $this->assertEquals($payload, $event->fresh()->payload);
        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessWebhookEventJob::class, 2);
    }

    public function test_duplicate_object_key_order_is_not_a_conflict_but_scalar_types_are(): void
    {
        $payload = $this->ping() + ['extension' => ['first' => 1, 'second' => true]];
        $this->receive($payload)->assertNoContent();
        $this->receive(array_reverse($payload, true))->assertNoContent();
        $payload['extension']['first'] = '1';
        $this->receive($payload)->assertStatus(409);
    }

    public function test_dispatch_failure_after_commit_still_acknowledges_durable_receipt(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('dispatch failed'));
        $this->receive($this->ping())->assertNoContent();
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertNull(WebhookEvent::query()->sole()->processed_at);
    }

    public function test_database_receipt_failure_is_not_acknowledged_and_dispatch_never_runs(): void
    {
        WebhookEvent::creating(function (): void {
            throw new \RuntimeException('simulated SQL receipt failure');
        });
        $this->receive($this->ping())->assertStatus(500);
        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_changed_receiver_key_context_cannot_overwrite_original_event(): void
    {
        $this->receive($this->ping())->assertNoContent();
        config(['services.pixelforge.api_key' => 'rotated-key']);
        $this->receive($this->ping())->assertStatus(409);
        $this->assertSame(hash('sha256', 'workflow-test-key'), WebhookEvent::query()->sole()->provider_key_fingerprint);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_invalid_signature_cannot_persist_or_dispatch_an_event(): void
    {
        $payload = $this->ping();
        $raw = json_encode($payload);
        $this->call('POST', '/_test/pixelforge-webhook', server: ['HTTP_PIXELFORGE_SIGNATURE' => 't=1,v1='.str_repeat('a', 64)], content: $raw)->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_ping_and_unknown_signed_events_are_recorded_and_ignored_once(): void
    {
        foreach (['ping', 'future.event'] as $i => $type) {
            $payload = array_replace($this->ping(), ['id' => 'evt_ignored'.$i, 'type' => $type]);
            $this->receive($payload)->assertNoContent();
            app()->call([new ProcessWebhookEventJob($payload['id']), 'handle']);
            app()->call([new ProcessWebhookEventJob($payload['id']), 'handle']);
            $event = WebhookEvent::query()->findOrFail($payload['id']);
            $this->assertSame('ignored', $event->outcome);
            $this->assertNotNull($event->processed_at);
        }
        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('generation_charges', 0);
    }

    public function test_early_completion_duplicate_jobs_and_distinct_completion_events_settle_once(): void
    {
        $generation = $this->generation();
        $object = $this->providerPayload($generation, 'succeeded');
        foreach (['evt_completion1', 'evt_completion2'] as $id) {
            $this->receive($this->eventPayload($object, $id))->assertNoContent();
            app()->call([new ProcessWebhookEventJob($id), 'handle']);
            app()->call([new ProcessWebhookEventJob($id), 'handle']);
        }
        $this->assertSame('succeeded', $generation->fresh()->provider_status->value);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertSame(2, WebhookEvent::query()->whereNotNull('processed_at')->count());
    }

    public function test_processing_after_success_is_stale_without_second_financial_effect(): void
    {
        $generation = $this->generation();
        foreach (['succeeded', 'processing'] as $status) {
            $id = 'evt_'.$status;
            $this->receive($this->eventPayload($this->providerPayload($generation, $status), $id))->assertNoContent();
            app()->call([new ProcessWebhookEventJob($id), 'handle']);
        }
        $this->assertSame('stale', WebhookEvent::query()->findOrFail('evt_processing')->outcome);
        $this->assertSame('succeeded', $generation->fresh()->provider_status->value);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_unmatched_invalid_and_conflicting_events_remain_inspectable_without_charges(): void
    {
        $generation = $this->generation();
        $cases = [
            'unmatched' => ['metadata' => ['generation_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'key_fingerprint' => hash('sha256', 'workflow-test-key')]],
            'invalid' => ['usage' => ['total_tokens' => -1]],
            'conflict' => ['metadata' => ['generation_id' => $generation->id, 'key_fingerprint' => str_repeat('f', 64)]],
        ];
        foreach ($cases as $outcome => $changes) {
            $id = 'evt_'.$outcome;
            $this->receive($this->eventPayload($this->providerPayload($generation, 'succeeded', $changes), $id))->assertNoContent();
            app()->call([new ProcessWebhookEventJob($id), 'handle']);
            $event = WebhookEvent::query()->findOrFail($id);
            $this->assertSame($outcome, $event->outcome);
            $this->assertNotNull($event->processed_at);
            $this->assertNotNull($event->error);
        }
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('generation_charges', 0);
        $this->assertNull($generation->fresh()->provider_id);
    }

    public function test_webhook_model_evidence_and_processed_marker_are_guarded(): void
    {
        $this->receive($this->ping())->assertNoContent();
        $event = WebhookEvent::query()->sole();
        try {
            $event->payload = ['changed' => true];
            $event->save();
            $this->fail('Evidence mutation should fail.');
        } catch (LogicException) {
            $this->assertEquals($this->ping(), $event->fresh()->payload);
        }
        $event = $event->fresh();
        $this->expectException(LogicException::class);
        $event->markProcessed('unsupported');
    }

    private function generation(): Generation
    {
        $generation = Generation::factory()->create();
        DB::transaction(fn () => $generation->prepareSubmission(hash('sha256', 'workflow-test-key')));

        return $generation;
    }

    private function ping(): array
    {
        return ['id' => 'evt_ping1', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601ZuluString()];
    }

    private function receive(array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;

        return $this->call('POST', '/_test/pixelforge-webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'workflow-test-secret'),
            'HTTP_PIXELFORGE_EVENT_ID' => $payload['id'],
            'HTTP_PIXELFORGE_EVENT_TYPE' => $payload['type'],
        ], content: $raw);
    }
}
