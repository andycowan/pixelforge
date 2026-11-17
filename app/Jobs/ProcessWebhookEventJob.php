<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Exceptions\ProviderEvidenceConflict;
use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProcessWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function handle(ApplyProviderGeneration $apply): void
    {
        $event = WebhookEvent::query()->find($this->eventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }
        if (! in_array($event->payload['type'], ['generation.queued', 'generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
            $this->finish('ignored');

            return;
        }
        try {
            $provider = ProviderGeneration::fromArray($event->payload['data']['object']);
        } catch (ProviderPayloadException) {
            $this->finish('invalid', 'invalid_provider_object');

            return;
        }
        $id = $provider->metadata['generation_id'] ?? '';
        if (! Str::isUlid($id) || ! Generation::query()->whereKey($id)->exists()) {
            $this->finish('unmatched', 'local_generation_missing');

            return;
        }

        try {
            // Apply owns generation-FIRST locking and the atomic processed marker.
            $apply->execute($id, $provider, $event->provider_key_fingerprint, $event->id);
        } catch (ProviderEvidenceConflict) {
            $this->finish('conflict', 'provider_evidence_conflict');
            report(new RuntimeException('Webhook correlation conflict for event '.$event->id));
        }
    }

    private function finish(string $outcome, ?string $code = null): void
    {
        DB::transaction(function () use ($outcome, $code): void {
            $event = WebhookEvent::query()->lockForUpdate()->find($this->eventId);
            $event?->markProcessed($outcome, $code === null ? null : ['code' => $code]);
        }, 3);
    }
}
