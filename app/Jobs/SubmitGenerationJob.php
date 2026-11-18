<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\GenerationSubmissionStatus;
use App\Enums\ProviderGenerationStatus;
use App\Exceptions\ProviderEvidenceConflict;
use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\CreateGeneration;
use App\Jobs\Middleware\ProviderRequestBudget;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\OutboxMessage;
use App\Services\GenerationSubmissionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

final class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $generationId) {}

    /** @return list<ProviderRequestBudget> */
    public function middleware(): array
    {
        return [new ProviderRequestBudget];
    }

    public function handle(ApplyProviderGeneration $apply, GenerationSubmissionPolicy $policy): void
    {
        $key = PixelforgeConnector::configuredKey();
        $fingerprint = $key === null ? null : hash('sha256', $key);
        $notify = false;
        $claim = DB::transaction(function () use ($fingerprint, &$notify): ?array {
            $notify = false;
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);
            if ($generation === null || $generation->provider_id !== null
                || ! in_array($generation->submission_status, [GenerationSubmissionStatus::Pending, GenerationSubmissionStatus::Uncertain], true)
                || ($generation->submission_error['retryable'] ?? true) === false
                || ($generation->next_attempt_at !== null && $generation->next_attempt_at->isFuture())) {
                return null;
            }
            if ($fingerprint === null || $generation->provider_key_fingerprint !== $fingerprint) {
                $notify = self::recordProblem($generation, ['code' => 'provider_key_context_changed', 'message' => 'Provider configuration requires operator attention.', 'retryable' => false], null, $generation->submission_status);

                return null;
            }
            if ($generation->submission_attempts >= 5 || $generation->created_at->lte(now()->subMinutes(30))) {
                $notify = self::recordProblem($generation, ['code' => 'automatic_submission_stopped', 'message' => 'Provider confirmation requires operator attention.', 'retryable' => false], null, $generation->submission_status);

                return null;
            }
            $previouslyUncertain = $generation->submission_status === GenerationSubmissionStatus::Uncertain;
            if (! $generation->claimSubmissionAttempt(now()->addSeconds(60))) {
                return null;
            }

            return [$generation, $previouslyUncertain];
        }, 3);
        if ($notify) {
            rescue(fn () => PublishOutboxJob::dispatch());
        }
        if ($claim === null) {
            return;
        }
        [$generation, $previouslyUncertain] = $claim;
        $request = new CreateGeneration($generation->id, [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'metadata' => ['generation_id' => $generation->id, 'key_fingerprint' => $fingerprint],
        ]);
        try {
            $response = (new PixelforgeConnector($key))->send($request);
        } catch (FatalRequestException) {
            $this->problem($generation, $policy->problem('transport_error', $previouslyUncertain, $generation->submission_attempts));

            return;
        }
        if (! $response->successful()) {
            try {
                $code = $response->json('error.code');
            } catch (JsonException) {
                $code = null;
            }
            $retryAfter = $response->header('Retry-After');
            $this->problem($generation, $policy->problem(is_string($code) ? $code : null, $previouslyUncertain, $generation->submission_attempts, is_string($retryAfter) ? $retryAfter : null));

            return;
        }
        try {
            $provider = $response->dtoOrFail();
        } catch (ProviderPayloadException|JsonException) {
            $this->problem($generation, $policy->problem('invalid_provider_response', $previouslyUncertain, $generation->submission_attempts));

            return;
        }
        try {
            $apply->execute($generation->id, $provider, $fingerprint);
        } catch (ProviderEvidenceConflict) {
            $this->problem($generation, ['error' => ['code' => 'provider_evidence_conflict', 'message' => 'Provider evidence requires operator attention.', 'retryable' => false], 'status' => GenerationSubmissionStatus::Uncertain, 'delay' => null]);
            report(new RuntimeException('Provider create evidence conflict for generation '.$generation->id));

            return;
        }
        if (in_array($provider->status, [ProviderGenerationStatus::Queued, ProviderGenerationStatus::Processing], true)) {
            try {
                ReconcileGenerationJob::dispatch($generation->id)->delay(now()->addSeconds(15)->ceilSecond());
            } catch (Throwable) {
                report(new RuntimeException('Reconciliation dispatch failed for generation '.$generation->id.'; durable work retained.'));
            }
        }
    }

    /** @param array{error:array{code:string,message:string,retryable:bool},status:GenerationSubmissionStatus,delay:?int} $problem */
    private function problem(Generation $claimed, array $problem): void
    {
        $retryAt = $problem['delay'] === null ? null : now()->addSeconds($problem['delay']);
        if ($retryAt !== null && $retryAt->gte($claimed->created_at->copy()->addMinutes(30))) {
            $retryAt = null;
            $problem['error']['retryable'] = false;
            if ($problem['status'] === GenerationSubmissionStatus::Pending) {
                $problem['status'] = GenerationSubmissionStatus::Rejected;
                $problem['error']['message'] = 'The provider did not create the request and automatic retries stopped.';
            } else {
                $problem['error']['message'] = 'Provider confirmation requires operator attention.';
            }
        }
        $notify = false;
        $scheduled = DB::transaction(function () use ($retryAt, $problem, &$notify): bool {
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);
            if ($generation === null) {
                return false;
            }
            $notify = self::recordProblem($generation, $problem['error'], $retryAt, $problem['status']);

            return $generation->provider_id === null && $retryAt !== null;
        }, 3);
        if ($notify) {
            rescue(fn () => PublishOutboxJob::dispatch());
        }
        if ($scheduled) {
            $this->release($retryAt->ceilSecond());
        }
    }

    /** @param  array{code:string,message:string,retryable:bool}  $error */
    private static function recordProblem(Generation $generation, array $error, ?CarbonInterface $retryAt, GenerationSubmissionStatus $status): bool
    {
        $revision = $generation->revision;
        $generation->recordSubmissionProblem($error, $retryAt, $status);
        if ($generation->submission_status === GenerationSubmissionStatus::Rejected) {
            CreditLedgerEntry::releaseHoldFor($generation);
        }
        if ($generation->revision === $revision) {
            return false;
        }
        OutboxMessage::recordGenerationChange($generation);

        return true;
    }
}
