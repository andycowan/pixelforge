<?php

namespace App\Services;

use App\Enums\GenerationSubmissionStatus;

final class GenerationSubmissionPolicy
{
    /** @return array{error: array{code:string,message:string,retryable:bool}, status:GenerationSubmissionStatus, delay:?int} */
    public function problem(?string $code, bool $previouslyUncertain, int $attempt, ?string $retryAfter = null): array
    {
        $knownNoCreate = in_array($code, ['validation_failed', 'content_policy_violation', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded', 'invalid_api_key', 'overloaded'], true);
        $status = $previouslyUncertain || ! $knownNoCreate ? GenerationSubmissionStatus::Uncertain : GenerationSubmissionStatus::Pending;
        if (! $previouslyUncertain && in_array($code, ['validation_failed', 'content_policy_violation'], true)) {
            $status = GenerationSubmissionStatus::Rejected;
        }
        $retry = in_array($code, [null, 'transport_error', 'invalid_provider_response', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true) && $attempt < 5;
        $delay = [5, 15, 30, 60, 120][min(4, max(0, $attempt - 1))] + random_int(0, 3);
        if ($retryAfter !== null && preg_match('/\A[0-9]{1,9}\z/D', $retryAfter)) {
            $delay = max($delay, (int) $retryAfter);
        }
        if ($delay > 1800) {
            $retry = false;
        }
        if ($status === GenerationSubmissionStatus::Pending && ! $retry) {
            $status = GenerationSubmissionStatus::Rejected;
        }
        $safeCode = in_array($code, ['validation_failed', 'content_policy_violation', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded', 'invalid_api_key', 'overloaded', 'idempotency_key_reused', 'transport_error', 'invalid_provider_response'], true) ? $code : 'unknown_provider_error';
        $message = match ($safeCode) {
            'validation_failed' => 'The provider rejected the request options.',
            'content_policy_violation' => 'The provider rejected the prompt under its content policy.',
            'daily_limit_exceeded' => 'The provider daily quota is exhausted; operator attention is required.',
            'invalid_api_key' => 'Provider authentication requires operator attention.',
            'idempotency_key_reused' => 'Provider request identity requires operator attention.',
            default => match (true) {
                $retry => 'Awaiting provider confirmation; a retry is scheduled.',
                $status === GenerationSubmissionStatus::Rejected => 'The provider did not create the request and automatic retries stopped.',
                default => 'Provider confirmation requires operator attention.',
            },
        };

        return ['error' => ['code' => $safeCode, 'message' => $message, 'retryable' => $retry], 'status' => $status, 'delay' => $retry ? $delay : null];
    }
}
