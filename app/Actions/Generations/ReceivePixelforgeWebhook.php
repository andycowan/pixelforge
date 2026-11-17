<?php

namespace App\Actions\Generations;

use App\Jobs\ProcessWebhookEventJob;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ReceivePixelforgeWebhook
{
    /** @param array<string, mixed> $payload */
    public function execute(array $payload, string $fingerprint): void
    {
        $event = DB::transaction(function () use ($payload, $fingerprint): WebhookEvent {
            $event = WebhookEvent::query()->firstOrCreate(['id' => $payload['id']], [
                'payload' => $payload,
                'provider_key_fingerprint' => $fingerprint,
                'received_at' => now(),
            ]);
            // JSONB does not preserve object-key ordering; compare values without losing scalar types.
            if ($event->provider_key_fingerprint !== $fingerprint || ! $this->sameEvidence($event->payload, $payload)) {
                throw new HttpException(409, 'Webhook event identity conflicts with recorded evidence.');
            }

            return $event;
        }, 3);

        if ($event->processed_at === null) {
            try {
                ProcessWebhookEventJob::dispatch($event->id);
            } catch (Throwable) {
                // Do not log dispatcher exceptions: a sync driver could include provider evidence.
                report(new RuntimeException('Webhook dispatch failed for event '.$event->id.'; durable receipt retained.'));
            }
        }
    }

    private function sameEvidence(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! $this->sameEvidence($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
