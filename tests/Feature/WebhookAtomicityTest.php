<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class WebhookAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_evidence_cannot_be_mutated_with_events_disabled(): void
    {
        $event = WebhookEvent::query()->create([
            'id' => 'evt_immutable', 'payload' => ['object' => 'event', 'type' => 'ping'],
            'provider_key_fingerprint' => str_repeat('a', 64), 'received_at' => now(),
        ]);
        $event->payload = ['object' => 'event', 'type' => 'changed'];

        $this->expectException(LogicException::class);
        $event->saveQuietly();
    }

    public function test_cancelled_inbox_marker_rolls_back_the_entire_settlement(): void
    {
        $wire = json_decode(file_get_contents(base_path('tests/Fixtures/provider/succeeded.json')), true, flags: JSON_THROW_ON_ERROR);
        $generation = Generation::factory()->for(User::factory())->create([
            'prompt' => $wire['prompt'], 'size' => $wire['size'], 'quality' => $wire['quality'],
            'reference_images' => $wire['reference_images'],
        ]);
        $fingerprint = str_repeat('a', 64);
        DB::transaction(fn () => $generation->prepareSubmission($fingerprint));
        $wire['metadata'] = ['generation_id' => $generation->id, 'key_fingerprint' => $fingerprint];
        $event = WebhookEvent::query()->create([
            'id' => 'evt_cancelled',
            'payload' => ['object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $wire]],
            'provider_key_fingerprint' => $fingerprint, 'received_at' => now(),
        ]);

        WebhookEvent::updating(fn () => false);
        try {
            app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($wire), $fingerprint, $event->id);
            $this->fail('A cancelled inbox marker must abort settlement.');
        } catch (LogicException) {
            $this->assertNull($generation->fresh()->provider_status);
            $this->assertDatabaseCount('generation_charges', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
            $this->assertNull($event->fresh()->processed_at);
        } finally {
            WebhookEvent::flushEventListeners();
        }
    }
}
