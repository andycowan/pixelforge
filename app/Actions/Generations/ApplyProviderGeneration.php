<?php

namespace App\Actions\Generations;

use App\Enums\ProviderGenerationStatus;
use App\Enums\ProviderTransitionOutcome;
use App\Exceptions\ProviderEvidenceConflict;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Jobs\PublishOutboxJob;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\OutboxMessage;
use App\Models\WebhookEvent;
use App\Services\GenerationPricing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ApplyProviderGeneration
{
    public function __construct(private readonly GenerationPricing $pricing) {}

    public function execute(string $generationId, ProviderGeneration $provider, string $keyFingerprint, ?string $webhookEventId = null): ProviderTransitionOutcome
    {
        $notify = false;
        try {
            $outcome = DB::transaction(function () use ($generationId, $provider, $keyFingerprint, $webhookEventId, &$notify): ProviderTransitionOutcome {
                $notify = false; // A retried attempt starts again.
                // Every ingress locks in this order; never inbox then generation.
                $generation = Generation::query()->lockForUpdate()->findOrFail($generationId);
                if (($provider->metadata['generation_id'] ?? null) !== $generationId
                    || ($provider->metadata['key_fingerprint'] ?? null) !== $keyFingerprint
                    || ($generation->provider_id !== null && $generation->provider_id !== $provider->id)
                    || $generation->provider_key_fingerprint !== $keyFingerprint) {
                    throw new ProviderEvidenceConflict('Observation correlation does not agree.');
                }
                $event = $webhookEventId === null ? null : WebhookEvent::query()->lockForUpdate()->findOrFail($webhookEventId);
                if ($event !== null) {
                    if ($event->provider_key_fingerprint !== $keyFingerprint
                        || self::canonical($event->payload['data']['object'] ?? null) !== self::canonical($provider->toArray())) {
                        throw new ProviderEvidenceConflict('Webhook evidence does not agree with the observation.');
                    }
                    if ($event->processed_at !== null) {
                        return ProviderTransitionOutcome::Duplicate;
                    }
                }
                $revision = $generation->revision;
                $outcome = $generation->applyObservation($provider, $keyFingerprint);
                if ($outcome === ProviderTransitionOutcome::Conflict) {
                    $event?->markProcessed('conflict', ['code' => 'provider_terminal_conflict']);
                    report(new RuntimeException('Provider lifecycle conflict for generation '.$generation->id));

                    return $outcome;
                }
                // Duplicate terminal observations still validate/repair a missing
                // settlement; ledger effects are keyed per generation, so repeats are no-ops.
                // Lock order: generation row, then the ledger insert's implicit owner key-share
                // lock. Settlement never takes the owner FOR UPDATE lock (D-B05).
                if ($generation->provider_status === ProviderGenerationStatus::Succeeded
                    && $provider->status === ProviderGenerationStatus::Succeeded) {
                    CreditLedgerEntry::settle($generation, GenerationCharge::ensureFor($generation, $this->pricing));
                } elseif ($generation->provider_status === ProviderGenerationStatus::Failed
                    && $provider->status === ProviderGenerationStatus::Failed) {
                    CreditLedgerEntry::releaseHoldFor($generation);
                }
                if ($generation->revision !== $revision) {
                    OutboxMessage::recordGenerationChange($generation);
                    $notify = true;
                }
                $event?->markProcessed(strtolower($outcome->name));

                return $outcome;
            }, 3);
        } catch (QueryException $exception) {
            // Different local rows cannot share a provider identity, even if
            // both transactions raced past the model's correlation check.
            if ((string) $exception->getCode() === '23505' && str_contains($exception->getMessage(), 'generations_provider_id_unique')) {
                throw new ProviderEvidenceConflict('Provider identity is already bound to another generation.');
            }

            throw $exception;
        }
        if ($notify) {
            // Intent is durable; a failed dispatch is repaired by the recovery sweep.
            rescue(fn () => PublishOutboxJob::dispatch());
        }

        return $outcome;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
