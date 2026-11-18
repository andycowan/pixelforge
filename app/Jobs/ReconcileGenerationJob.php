<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\GenerationSubmissionStatus;
use App\Enums\ProviderGenerationStatus;
use App\Exceptions\ProviderEvidenceConflict;
use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\GetGeneration;
use App\Jobs\Middleware\ProviderRequestBudget;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;

final class ReconcileGenerationJob implements ShouldQueue
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

    public function handle(ApplyProviderGeneration $apply): void
    {
        $key = PixelforgeConnector::configuredKey();
        $fingerprint = $key === null ? null : hash('sha256', $key);
        $generation = DB::transaction(function () use ($fingerprint): ?Generation {
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);
            if ($generation === null || ! $this->checkable($generation) || $generation->next_attempt_at === null || $generation->next_attempt_at->isFuture()) {
                return null;
            }
            if ($fingerprint === null || $generation->provider_key_fingerprint !== $fingerprint || $generation->created_at->lte(now()->subMinutes(30))) {
                $generation->deferProviderCheck(null);
                report(new RuntimeException('Automatic provider checks stopped for generation '.$generation->id.'; operator attention required.'));

                return null;
            }

            return $generation->claimProviderCheck(now()->addSeconds(60)) ? $generation : null;
        }, 3);
        if ($generation === null) {
            return;
        }
        $delay = 15;
        try {
            $response = (new PixelforgeConnector($key))->send(new GetGeneration($generation->provider_id));
            if ($response->successful()) {
                $apply->execute($generation->id, $response->dtoOrFail(), $fingerprint);
            } else {
                $code = $response->json('error.code');
                if (! in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true) && $response->status() < 500) {
                    $delay = null;
                }
                $retryAfter = $response->header('Retry-After');
                if ($delay !== null && is_string($retryAfter) && preg_match('/\A[0-9]{1,9}\z/D', $retryAfter)) {
                    $delay = max($delay, (int) $retryAfter);
                }
            }
        } catch (FatalRequestException) {
        } catch (ProviderPayloadException|JsonException|ProviderEvidenceConflict) {
            $delay = null;
        }
        if ($delay !== null && now()->addSeconds($delay)->gte($generation->created_at->copy()->addMinutes(30))) {
            $delay = null;
        }
        $retryAt = $delay === null ? null : now()->addSeconds($delay);
        $scheduled = DB::transaction(function () use ($retryAt): bool {
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);
            if ($generation === null || ! $this->checkable($generation)) {
                return false;
            }
            $generation->deferProviderCheck($retryAt);

            return $retryAt !== null;
        }, 3);
        if ($scheduled && $retryAt !== null) {
            $this->release($retryAt->ceilSecond());
        } elseif ($delay === null) {
            report(new RuntimeException('Provider reconciliation requires operator attention for generation '.$generation->id));
        }
    }

    private function checkable(Generation $generation): bool
    {
        return $generation->submission_status === GenerationSubmissionStatus::Accepted
            && $generation->provider_id !== null
            && in_array($generation->provider_status, [ProviderGenerationStatus::Queued, ProviderGenerationStatus::Processing], true);
    }
}
