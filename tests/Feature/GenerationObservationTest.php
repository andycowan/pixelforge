<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\GenerationSubmissionStatus;
use App\Enums\ProviderGenerationStatus;
use App\Enums\ProviderTransitionOutcome;
use App\Exceptions\ProviderEvidenceConflict;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\GenerationEvidence;
use Tests\TestCase;

final class GenerationObservationTest extends TestCase
{
    use GenerationEvidence, RefreshDatabase;

    public function test_terminal_first_then_old_create_and_processing_never_clear_result_or_acceptance(): void
    {
        $generation = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        $this->assertSame(ProviderTransitionOutcome::Advance, $apply->execute($generation->id, $this->evidence($generation), $this->fingerprint()));
        $settled = $generation->fresh()->getAttributes();
        foreach (['queued', 'processing'] as $status) {
            $this->assertSame(ProviderTransitionOutcome::Stale, $apply->execute($generation->id, $this->evidence($generation, $status), $this->fingerprint()));
        }
        DB::transaction(function () use ($generation): void {
            Generation::query()->lockForUpdate()->findOrFail($generation->id)->recordSubmissionProblem(
                ['code' => 'transport_error', 'message' => 'Late timeout', 'retryable' => true], now()->addMinute(), GenerationSubmissionStatus::Uncertain,
            );
        });
        $this->assertSame($settled, $generation->fresh()->getAttributes());
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_processing_duplicate_success_sequence_and_opposing_terminal_do_not_rewrite_truth(): void
    {
        $generation = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        foreach (['queued', 'processing', 'succeeded'] as $status) {
            $this->assertSame(ProviderTransitionOutcome::Advance, $apply->execute($generation->id, $this->evidence($generation, $status), $this->fingerprint()));
        }
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $this->evidence($generation), $this->fingerprint()));
        $this->assertSame(ProviderTransitionOutcome::Conflict, $apply->execute($generation->id, $this->evidence($generation, 'failed'), $this->fingerprint()));
        $this->assertSame(ProviderGenerationStatus::Succeeded, $generation->fresh()->provider_status);
        $this->assertSame(3, $generation->fresh()->revision);
        $this->assertDatabaseCount('outbox_messages', 3);
        $this->assertDatabaseCount('generation_charges', 1);
    }

    public function test_failed_duplicate_is_free_and_success_after_failure_conflicts(): void
    {
        $generation = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        $this->assertSame(ProviderTransitionOutcome::Advance, $apply->execute($generation->id, $this->evidence($generation, 'failed', ['started_at' => null]), $this->fingerprint()));
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $this->evidence($generation, 'failed', ['started_at' => null]), $this->fingerprint()));
        $this->assertSame(ProviderTransitionOutcome::Conflict, $apply->execute($generation->id, $this->evidence($generation), $this->fingerprint()));
        $this->assertSame('0.000000000', $generation->fresh()->toHistoryArray()['actual_cost_usd']);
        $this->assertDatabaseCount('generation_charges', 0);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    #[DataProvider('identityChanges')]
    public function test_all_identity_sources_and_request_echo_must_agree(array $changes, bool $wrongFingerprint = false): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation, changes: $changes);
        try {
            app(ApplyProviderGeneration::class)->execute($generation->id, $provider, $wrongFingerprint ? str_repeat('b', 64) : $this->fingerprint());
            $this->fail('Expected evidence conflict.');
        } catch (ProviderEvidenceConflict) {
            $this->assertNull($generation->fresh()->provider_status);
            $this->assertDatabaseCount('generation_charges', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    public static function identityChanges(): array
    {
        return [
            'prompt' => [['prompt' => 'Different prompt']],
            'size' => [['size' => '1536x1024']],
            'quality' => [['quality' => 'high']],
            'references' => [['reference_images' => ['https://example.com/other.png']]],
            'metadata local id' => [['metadata' => ['generation_id' => 'another', 'key_fingerprint' => hash('sha256', 'test-key-never-live')]]],
            'metadata key' => [['metadata' => ['key_fingerprint' => str_repeat('b', 64)]]],
            'missing metadata' => [['metadata' => []]],
            'account context' => [[], true],
        ];
    }

    public function test_provider_binding_cannot_move_or_bind_to_another_generation(): void
    {
        $first = $this->preparedGeneration();
        $second = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($first->id, $this->evidence($first, 'queued'), $this->fingerprint());
        foreach ([$this->evidence($first, 'queued', ['id' => 'gen_other']), $this->evidence($second, 'queued', ['id' => 'gen_'.$first->id])] as $provider) {
            try {
                $apply->execute($provider->metadata['generation_id'], $provider, $this->fingerprint());
                $this->fail('Expected provider binding conflict.');
            } catch (ProviderEvidenceConflict) {
                $this->assertDatabaseCount('outbox_messages', 1);
            }
        }
    }

    public function test_claims_are_leases_not_public_revisions_and_late_error_cannot_regress_acceptance(): void
    {
        $generation = $this->preparedGeneration();
        DB::transaction(function () use ($generation): void {
            $row = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $this->assertTrue($row->claimSubmissionAttempt(now()->addMinute()));
            $this->assertFalse($row->claimSubmissionAttempt(now()->addMinute()));
            $this->assertSame(1, $row->submission_attempts);
            $this->assertSame(GenerationSubmissionStatus::Uncertain, $row->submission_status);
            $this->assertSame(0, $row->revision);
        });
        app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation, 'queued'), $this->fingerprint());
        DB::transaction(function () use ($generation): void {
            $row = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $this->assertFalse($row->claimProviderCheck(now()->addMinute()));
            $row->deferProviderCheck(now()->subSecond());
            $this->assertTrue($row->claimProviderCheck(now()->addMinute()));
            $row->recordSubmissionProblem(['code' => 'content_policy_violation', 'message' => 'secret diagnostic', 'retryable' => false], null, GenerationSubmissionStatus::Rejected);
            $this->assertSame(1, $row->revision);
            $this->assertSame(GenerationSubmissionStatus::Accepted, $row->submission_status);
        });
    }

    public function test_fingerprint_is_once_only_and_named_mutations_require_clean_rows(): void
    {
        $generation = $this->preparedGeneration();
        DB::transaction(function () use ($generation): void {
            $row = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $row->prepareSubmission($this->fingerprint());
            try {
                $row->prepareSubmission(str_repeat('a', 64));
                $this->fail('Expected immutable fingerprint.');
            } catch (ProviderEvidenceConflict) {
                $this->assertSame($this->fingerprint(), $row->provider_key_fingerprint);
            }
            $row->provider_id = 'gen_raw';
            try {
                $row->claimSubmissionAttempt(now()->addMinute());
                $this->fail('Expected dirty row rejection.');
            } catch (LogicException) {
                $this->assertNull($generation->fresh()->provider_id);
            }
        });
    }

    public function test_quiet_saves_do_not_bypass_reserved_or_creation_guards(): void
    {
        $generation = $this->preparedGeneration();
        $generation->provider_status = ProviderGenerationStatus::Succeeded;
        try {
            $generation->saveQuietly();
            $this->fail('Expected quiet update rejection.');
        } catch (LogicException) {
            $this->assertNull($generation->fresh()->provider_status);
        }
        $new = Generation::factory()->make();
        $new->provider_id = 'gen_invented';
        $this->expectException(LogicException::class);
        $new->saveQuietly();
    }

    public function test_uncertain_generation_cannot_be_deleted_even_with_events_muted(): void
    {
        $generation = $this->preparedGeneration();
        DB::transaction(fn () => Generation::query()->lockForUpdate()->findOrFail($generation->id)->claimSubmissionAttempt(now()->addMinute()));
        foreach ([true, false] as $quiet) {
            try {
                $quiet ? $generation->deleteQuietly() : Generation::withoutEvents(fn () => $generation->delete());
                $this->fail('Expected retention despite disabled model events.');
            } catch (LogicException) {
                $this->assertDatabaseHas('generations', ['id' => $generation->id, 'submission_status' => 'uncertain']);
            }
        }
    }

    public function test_history_is_owned_paginated_exact_and_redacts_operational_diagnostics(): void
    {
        $generation = $this->preparedGeneration();
        $this->assertNull($generation->toHistoryArray()['actual_cost_usd']);
        DB::transaction(function () use ($generation): void {
            $row = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $row->recordSubmissionProblem(['code' => 'content_policy_violation', 'message' => 'secret-key raw error', 'retryable' => false], null, GenerationSubmissionStatus::Uncertain);
        });
        $history = $generation->fresh()->toHistoryArray();
        $this->assertNull($history['actual_cost_usd']);
        $this->assertStringNotContainsString('not charged', strtolower($history['submission_message']));
        $this->assertStringNotContainsString('secret-key', json_encode($history));
        foreach (['provider_id', 'provider_key_fingerprint', 'submission_error', 'rates_snapshot', 'usage', 'quote_snapshot', 'provider_error'] as $key) {
            $this->assertArrayNotHasKey($key, $history);
        }
        Generation::factory()->count(21)->for($generation->user)->create();
        Generation::factory()->for(User::factory())->create();
        $page = Generation::historyFor($generation->user);
        $this->assertSame(22, $page->total());
        $this->assertCount(20, $page->items());
        $this->assertSame(20, $page->perPage());
        $this->assertIsArray($page->items()[0]);
    }

    public function test_known_local_rejection_is_free_and_cannot_accept_later_evidence(): void
    {
        $generation = $this->preparedGeneration();
        DB::transaction(fn () => Generation::query()->lockForUpdate()->findOrFail($generation->id)->recordSubmissionProblem(
            ['code' => 'content_policy_violation', 'message' => 'Private rejection', 'retryable' => false], null, GenerationSubmissionStatus::Rejected,
        ));
        $this->assertSame('0.000000000', $generation->fresh()->toHistoryArray()['actual_cost_usd']);
        $this->assertSame(ProviderTransitionOutcome::Conflict, app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint()));
        $this->assertDatabaseCount('generation_charges', 0);
        $this->assertSame(GenerationSubmissionStatus::Rejected, $generation->fresh()->submission_status);
    }
}
