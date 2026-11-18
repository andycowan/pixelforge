<?php

namespace Tests\Feature;

use App\Enums\GenerationSubmissionStatus;
use App\Services\GenerationSubmissionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GenerationSubmissionPolicyTest extends TestCase
{
    #[DataProvider('classifications')]
    public function test_error_classification_preserves_uncertainty(string $code, bool $uncertain, GenerationSubmissionStatus $status, bool $retry): void
    {
        $result = (new GenerationSubmissionPolicy)->problem($code, $uncertain, 1);
        $this->assertSame($status, $result['status']);
        $this->assertSame($retry, $result['error']['retryable']);
        $this->assertSame($retry, $result['delay'] !== null);
    }

    public static function classifications(): array
    {
        $cases = [];
        foreach (['validation_failed', 'content_policy_violation', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded', 'invalid_api_key', 'overloaded', 'idempotency_key_reused', 'transport_error', 'invalid_provider_response', 'surprise'] as $code) {
            foreach ([false, true] as $uncertain) {
                $retry = in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'transport_error', 'invalid_provider_response'], true);
                $status = $uncertain || in_array($code, ['idempotency_key_reused', 'transport_error', 'invalid_provider_response', 'surprise'], true) ? GenerationSubmissionStatus::Uncertain : ($retry ? GenerationSubmissionStatus::Pending : GenerationSubmissionStatus::Rejected);
                $cases[$code.($uncertain ? '-uncertain' : '-first')] = [$code, $uncertain, $status, $retry];
            }
        }

        return $cases;
    }

    public function test_retry_budget_backoff_and_retry_after_are_bounded(): void
    {
        $policy = new GenerationSubmissionPolicy;
        $this->assertGreaterThanOrEqual(120, $policy->problem('rate_limit_exceeded', false, 1, '120')['delay']);
        $this->assertGreaterThanOrEqual(60, $policy->problem('transport_error', true, 4)['delay']);
        $this->assertNull($policy->problem('transport_error', true, 5)['delay']);
        $this->assertNull($policy->problem('overloaded', false, 1, '999999999')['delay']);
        $this->assertLessThan(10, $policy->problem('overloaded', false, 1, '-5')['delay']);
        $this->assertSame('unknown_provider_error', $policy->problem('SECRET BODY', true, 1)['error']['code']);
    }

    public function test_exhausted_never_created_submission_is_final_but_uncertain_stays_paused(): void
    {
        $policy = new GenerationSubmissionPolicy;
        $this->assertSame(GenerationSubmissionStatus::Pending, $policy->problem('overloaded', false, 4)['status']);
        $this->assertSame(GenerationSubmissionStatus::Rejected, $policy->problem('overloaded', false, 5)['status']);
        $this->assertSame(GenerationSubmissionStatus::Rejected, $policy->problem('rate_limit_exceeded', false, 1, '999999999')['status']);
        $this->assertSame(GenerationSubmissionStatus::Uncertain, $policy->problem('overloaded', true, 5)['status']);
    }
}
