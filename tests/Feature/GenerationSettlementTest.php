<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\ProviderTransitionOutcome;
use App\Exceptions\ProviderEvidenceConflict;
use App\Models\OutboxMessage;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\GenerationEvidence;
use Tests\TestCase;

final class GenerationSettlementTest extends TestCase
{
    use GenerationEvidence, RefreshDatabase;

    public function test_settlement_uses_accepted_not_quote_rates_and_rounds_up_once_to_a_whole_cent(): void
    {
        $generation = $this->preparedGeneration();
        $rates = ['input_text_tokens' => '0.001', 'input_image_tokens' => '0', 'output_tokens' => '0', 'reasoning_tokens' => '0'];
        $usage = ['input_text_tokens' => 2, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'total_tokens' => 2];
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($generation->id, $this->evidence($generation, 'queued', ['rates' => $rates]), $this->fingerprint());
        $apply->execute($generation->id, $this->evidence($generation, changes: ['rates' => $rates, 'usage' => $usage]), $this->fingerprint());
        $charge = $generation->fresh()->charge;
        $this->assertSame(2, $charge->provider_cost_nanodollars);
        // 2 provider nanodollars x 5/4 = 2.5 nanodollars, which still charges one whole cent.
        $this->assertSame(10_000_000, $charge->customer_cost_nanodollars);
        $this->assertSame('0.010000000', $generation->fresh()->toHistoryArray()['actual_cost_usd']);
        $this->assertSame(5, $charge->markup_numerator);
        $this->assertSame(4, $charge->markup_denominator);
        $this->assertSame('ceiling', $charge->rounding);
        $this->assertSame(2, $charge->scale);
        $this->assertSame('2026-11-17T12:00:20+00:00', $charge->provider_completed_at->toIso8601String());
        $message = OutboxMessage::query()->latest('revision')->firstOrFail();
        $this->assertSame(['revision' => 2, 'generation_id' => $generation->id], $message->payload);
        $this->assertNull($message->published_at);
        $this->assertSame($generation->user_id, $message->user_id);
    }

    public function test_fractional_provider_times_round_trip_without_changing_duplicate_evidence(): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation, changes: [
            'created_at' => '2026-11-17T12:00:00.123456Z',
            'started_at' => '2026-11-17T12:00:02.234567Z',
            'completed_at' => '2026-11-17T12:00:20.345678Z',
            'image_url_expires_at' => '2026-11-18T12:00:20.345678Z',
        ]);
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($generation->id, $provider, $this->fingerprint());
        $row = $generation->fresh();
        $this->assertSame('123456', $row->provider_created_at->format('u'));
        $this->assertSame('234567', $row->provider_started_at->format('u'));
        $this->assertSame('345678', $row->provider_completed_at->format('u'));
        $this->assertSame('345678', $row->image_url_expires_at->format('u'));
        $this->assertSame('345678', $row->charge->provider_completed_at->format('u'));
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $provider, $this->fingerprint()));
        $this->assertSame(1, $generation->fresh()->revision);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_whole_cent_migration_recomputes_existing_prices_exactly_in_both_directions(): void
    {
        $generation = $this->preparedGeneration();
        app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        $provider = $generation->fresh()->charge->provider_cost_nanodollars;
        $migration = require database_path('migrations/2026_11_17_150000_round_customer_prices_up_to_whole_cents.php');
        $row = fn (): array => [
            (array) DB::table('generation_charges')->first(['customer_cost_nanodollars', 'rounding', 'scale']),
            (array) DB::table('generations')->first(['estimated_cost_nanodollars', 'maximum_cost_nanodollars']),
        ];
        $ceilingCents = fn (int $p): int => intdiv($p * 5 + 39_999_999, 40_000_000) * 10_000_000;
        $current = $row();
        $this->assertSame([['customer_cost_nanodollars' => $ceilingCents($provider), 'rounding' => 'ceiling', 'scale' => 2],
            ['estimated_cost_nanodollars' => 80_000_000, 'maximum_cost_nanodollars' => 90_000_000]], $current);

        $migration->down();
        // Old policy: x 5/4 rounded half-up to the nanodollar, from the same stored inputs.
        $this->assertSame([['customer_cost_nanodollars' => intdiv($provider * 5 + 2, 4), 'rounding' => 'half_up', 'scale' => 9],
            ['estimated_cost_nanodollars' => 72_206_250, 'maximum_cost_nanodollars' => 86_275_000]], $row());
        $this->assertSame('half_up', $generation->fresh()->quote_snapshot['policy']['rounding']);

        $migration->up();
        $this->assertSame($current, $row());
        $this->assertSame(['ceiling', 2], [$generation->fresh()->quote_snapshot['policy']['rounding'], $generation->fresh()->quote_snapshot['policy']['precision']]);
        try {
            // A savepoint keeps the surrounding test transaction usable.
            DB::transaction(fn () => DB::table('generation_charges')->update(['customer_cost_nanodollars' => $ceilingCents($provider) + 1]));
            $this->fail('A charge off the whole-cent formula must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_settled_money_above_javascript_safe_integer_range_remains_an_exact_string(): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation, changes: [
            'rates' => ['input_text_tokens' => '7205759403792.794', 'input_image_tokens' => '0', 'output_tokens' => '0', 'reasoning_tokens' => '0'],
            'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'total_tokens' => 1],
        ]);
        app(ApplyProviderGeneration::class)->execute($generation->id, $provider, $this->fingerprint());
        $this->assertSame(7_205_759_403_792_794, $generation->fresh()->charge->provider_cost_nanodollars);
        // x 5/4 = 9,007,199,254,740,992.5, rounded up to the cent: still above JavaScript's safe integer range.
        $this->assertSame(9_007_199_260_000_000, $generation->fresh()->charge->customer_cost_nanodollars);
        $this->assertSame('9007199.260000000', $generation->fresh()->toHistoryArray()['actual_cost_usd']);
    }

    public function test_retained_ownerless_records_keep_settlement_and_nullable_outbox_ownership(): void
    {
        $generation = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        // Trusted operator retention path; the product deletion boundary lives on User.
        DB::table('users')->where('id', $generation->user_id)->delete();
        $this->assertNull($generation->fresh()->user_id);
        $this->assertNull(OutboxMessage::query()->firstOrFail()->user_id);
        $apply->execute($generation->id, $this->evidence($generation, changes: ['image_url' => 'https://example.com/result.png?signature=ownerless']), $this->fingerprint());
        $this->assertSame(0, OutboxMessage::query()->whereNotNull('user_id')->count());
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    #[DataProvider('changedEvidence')]
    public function test_accepted_or_terminal_evidence_cannot_be_rewritten(array $changes): void
    {
        $generation = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        $before = $generation->fresh()->getAttributes();
        try {
            $apply->execute($generation->id, $this->evidence($generation, changes: $changes), $this->fingerprint());
            $this->fail('Expected immutable evidence conflict.');
        } catch (ProviderEvidenceConflict) {
            $this->assertSame($before, $generation->fresh()->getAttributes());
            $this->assertDatabaseCount('generation_charges', 1);
            $this->assertDatabaseCount('outbox_messages', 1);
        }
    }

    public static function changedEvidence(): array
    {
        return [
            'usage' => [['usage' => ['input_text_tokens' => 4, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1100, 'total_tokens' => 2204]]],
            'rates' => [['rates' => ['input_text_tokens' => '6.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']]],
            'equivalent rate string still changed evidence' => [['rates' => ['input_text_tokens' => '5.0', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']]],
            'created' => [['created_at' => '2026-11-17T11:59:59Z']],
            'started' => [['started_at' => '2026-11-17T12:00:03Z']],
            'completed' => [['completed_at' => '2026-11-17T12:00:21Z']],
            'expiry' => [['image_url_expires_at' => '2026-11-18T12:00:21Z']],
        ];
    }

    public function test_duplicate_success_may_refresh_url_without_recharging_or_extending_expiry(): void
    {
        $generation = $this->preparedGeneration();
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $this->evidence($generation, changes: ['image_url' => 'https://example.com/result.png?signature=fresh']), $this->fingerprint()));
        $this->assertSame(2, $generation->fresh()->revision);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    public function test_valid_duplicate_success_repairs_missing_charge_but_rejects_existing_inconsistent_ledger(): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation);
        $apply = app(ApplyProviderGeneration::class);
        $apply->execute($generation->id, $provider, $this->fingerprint());
        DB::table('generation_charges')->where('generation_id', $generation->id)->delete();
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $provider, $this->fingerprint()));
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        DB::table('generation_charges')->where('generation_id', $generation->id)->update(['provider_cost_nanodollars' => 0, 'customer_cost_nanodollars' => 0]);
        $this->expectException(ProviderEvidenceConflict::class);
        $apply->execute($generation->id, $provider, $this->fingerprint());
    }

    public function test_charge_and_outbox_intent_are_immutable_even_with_events_muted(): void
    {
        $generation = $this->preparedGeneration();
        app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        foreach ([$generation->fresh()->charge, OutboxMessage::query()->firstOrFail()] as $model) {
            try {
                $model->saveQuietly();
                $this->fail('Expected immutable financial evidence.');
            } catch (LogicException) {
                $this->assertTrue($model->exists);
            }
            try {
                $model->deleteQuietly();
                $this->fail('Expected retained financial evidence.');
            } catch (LogicException) {
                $this->assertTrue($model->exists);
            }
        }
    }

    #[DataProvider('outboxFailures')]
    public function test_outbox_failure_rolls_back_success_charge_and_inbox_marker(bool $cancelSave): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation);
        $event = $this->inbox($provider->toArray(), 'evt_rollback');
        OutboxMessage::creating(function () use ($cancelSave): bool {
            if ($cancelSave) {
                return false;
            }
            throw new RuntimeException('Injected outbox failure');
        });
        try {
            app(ApplyProviderGeneration::class)->execute($generation->id, $provider, $this->fingerprint(), $event->id);
            $this->fail('Expected outbox failure.');
        } catch (RuntimeException|LogicException $exception) {
            $this->assertSame($cancelSave ? 'Generation notification intent was not persisted.' : 'Injected outbox failure', $exception->getMessage());
        } finally {
            OutboxMessage::flushEventListeners();
        }
        $this->assertNull($generation->fresh()->provider_status);
        $this->assertNull($event->fresh()->processed_at);
        $this->assertDatabaseCount('generation_charges', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function outboxFailures(): array
    {
        return ['exception' => [false], 'cancelled Eloquent save' => [true]];
    }

    public function test_same_event_and_different_events_for_one_completion_have_one_effect(): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation);
        $event = $this->inbox($provider->toArray(), 'evt_first');
        $apply = app(ApplyProviderGeneration::class);
        $this->assertSame(ProviderTransitionOutcome::Advance, $apply->execute($generation->id, $provider, $this->fingerprint(), $event->id));
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $provider, $this->fingerprint(), $event->id));
        $other = $this->inbox($provider->toArray(), 'evt_second');
        $this->assertSame(ProviderTransitionOutcome::Duplicate, $apply->execute($generation->id, $provider, $this->fingerprint(), $other->id));
        $this->assertSame('advance', $event->fresh()->outcome);
        $this->assertSame('duplicate', $other->fresh()->outcome);
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $conflict = $this->evidence($generation, 'failed');
        $failed = $this->inbox($conflict->toArray(), 'evt_opposing');
        $this->assertSame(ProviderTransitionOutcome::Conflict, $apply->execute($generation->id, $conflict, $this->fingerprint(), $failed->id));
        $this->assertSame('conflict', $failed->fresh()->outcome);
        $this->assertNotNull($failed->fresh()->processed_at);
    }

    public function test_inbox_fingerprint_and_original_wire_object_must_both_agree(): void
    {
        $generation = $this->preparedGeneration();
        $provider = $this->evidence($generation);
        $changed = $provider->toArray();
        $changed['usage']['input_text_tokens'] = '3';
        foreach ([[$changed, $this->fingerprint()], [$provider->toArray(), str_repeat('a', 64)]] as $index => [$payload, $fingerprint]) {
            $event = $this->inbox($payload, 'evt_mismatch'.$index, $fingerprint);
            try {
                app(ApplyProviderGeneration::class)->execute($generation->id, $provider, $this->fingerprint(), $event->id);
                $this->fail('Expected inbox evidence conflict.');
            } catch (ProviderEvidenceConflict) {
                $this->assertNull($event->fresh()->processed_at);
                $this->assertNull($generation->fresh()->provider_status);
                $this->assertDatabaseCount('generation_charges', 0);
            }
        }
    }

    public function test_database_uniqueness_backs_charge_and_outbox_deduplication(): void
    {
        $generation = $this->preparedGeneration();
        app(ApplyProviderGeneration::class)->execute($generation->id, $this->evidence($generation), $this->fingerprint());
        foreach (['generation_charges', 'outbox_messages'] as $table) {
            $row = (array) DB::table($table)->first();
            if ($table === 'outbox_messages') {
                $row['id'] = (string) Str::ulid();
            }
            try {
                DB::transaction(fn () => DB::table($table)->insert($row));
                $this->fail('Expected unique database constraint.');
            } catch (QueryException $exception) {
                $this->assertSame('23505', (string) $exception->getCode());
            }
        }
    }

    /** @param array<string, mixed> $object */
    private function inbox(array $object, string $id, ?string $fingerprint = null): WebhookEvent
    {
        // Receipt model API is deliberately not part of the settlement contract.
        DB::table('webhook_events')->insert([
            'id' => $id,
            'payload' => json_encode(['id' => $id, 'object' => 'event', 'type' => 'generation.'.$object['status'], 'data' => ['object' => $object]], JSON_THROW_ON_ERROR),
            'provider_key_fingerprint' => $fingerprint ?? $this->fingerprint(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WebhookEvent::query()->findOrFail($id);
    }
}
