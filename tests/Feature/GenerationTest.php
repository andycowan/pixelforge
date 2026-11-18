<?php

namespace Tests\Feature;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationSubmissionStatus;
use App\Enums\ProviderGenerationStatus;
use App\Enums\ProviderTransitionOutcome;
use App\Models\Generation;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;
use ValueError;

final class GenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_it_creates_a_pending_unobserved_owned_request(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        $this->assertTrue(Str::isUlid($generation->id));
        $this->assertSame($this->user->id, $generation->user_id);
        $this->assertTrue(Str::isUuid($generation->submission_token));
        $this->assertSame(GenerationSubmissionStatus::Pending, $generation->submission_status);
        $this->assertSame(0, $generation->submission_attempts);
        $this->assertSame(0, $generation->revision);
        $this->assertSame([], $generation->reference_images);
        $this->assertNull($generation->provider_status);
        $this->assertNull($generation->provider_id);
        $this->assertNull($generation->provider_key_fingerprint);
        $this->assertNull($generation->rates_snapshot);
        $this->assertNull($generation->usage);
        $this->assertNull($generation->image_url);
        $this->assertNull($generation->provider_error);
        $this->assertNull($generation->last_attempt_at);
        $this->assertNull($generation->next_attempt_at);
        $this->assertTrue($generation->user->is($this->user));
        $this->assertTrue($this->user->generations->contains($generation));

        $this->assertDatabaseHas('generations', [
            'id' => $generation->id,
            'submission_status' => 'pending',
            'submission_attempts' => 0,
            'revision' => 0,
        ]);
    }

    public function test_owner_scoped_query_returns_only_that_users_generations(): void
    {
        $mine = Generation::factory()->for($this->user)->create();
        $alsoMine = Generation::factory()->for($this->user)->create();
        $theirs = Generation::factory()->for(User::factory())->create();

        $found = Generation::query()->forUser($this->user)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $alsoMine->id], $found);
        $this->assertNotContains($theirs->id, $found);
    }

    public function test_an_unsaved_user_cannot_scope_ownerless_generation_records(): void
    {
        $owner = User::factory()->create();
        $generation = Generation::factory()->for($owner)->create();
        DB::table('users')->where('id', $owner->id)->delete();

        $this->assertDatabaseHas('generations', ['id' => $generation->id, 'user_id' => null]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An owner scope requires a persisted user.');

        Generation::query()->forUser(new User)->get();
    }

    public function test_it_casts_values_and_preserves_reference_order_and_duplicates(): void
    {
        $references = [
            'https://example.com/reference.png',
            'https://example.com/reference.png',
            'http://example.com/other.jpg',
        ];

        $generation = Generation::factory()->for($this->user)->create([
            'size' => GenerationSize::Landscape,
            'quality' => GenerationQuality::High,
            'reference_images' => $references,
        ]);

        $generation->refresh();

        $this->assertSame(GenerationSize::Landscape, $generation->size);
        $this->assertSame(GenerationQuality::High, $generation->quality);
        $this->assertSame($references, $generation->reference_images);
        $this->assertSame('5.00', $generation->quote_snapshot['rates']['input_text_tokens']);
        $this->assertSame('5/4', $generation->quote_snapshot['policy']['markup']);
        $this->assertSame('USD', $generation->quote_snapshot['policy']['currency']);
        $this->assertSame('1536x1024', $generation->toHistoryArray()['size']);
        $this->assertSame('high', $generation->toHistoryArray()['quality']);
    }

    public function test_history_projection_uses_exact_money_strings_and_omits_internal_evidence(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        $history = $generation->toHistoryArray();

        $this->assertSame([
            'id' => $generation->id,
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'submission_status' => 'pending',
            'provider_status' => null,
            'estimated_cost_usd' => '0.080000000',
            'maximum_cost_usd' => '0.090000000',
            'actual_cost_usd' => null,
            'submission_message' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'created_at' => $generation->created_at?->toIso8601String(),
        ], $history);

        foreach (['provider_id', 'provider_key_fingerprint', 'submission_token', 'quote_snapshot', 'usage', 'provider_error'] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $history);
        }

        $zero = Generation::factory()->for($this->user)->create([
            'estimated_cost_nanodollars' => 0,
            'maximum_cost_nanodollars' => 0,
        ]);
        $this->assertSame('0.000000000', $zero->toHistoryArray()['estimated_cost_usd']);
        $this->assertSame('0.000000000', $zero->toHistoryArray()['maximum_cost_usd']);

        $cent = Generation::factory()->for($this->user)->create([
            'estimated_cost_nanodollars' => 10_000_000,
            'maximum_cost_nanodollars' => 10_000_000,
        ]);
        $this->assertSame('0.010000000', $cent->toHistoryArray()['estimated_cost_usd']);

        $large = Generation::factory()->for($this->user)->create([
            'estimated_cost_nanodollars' => 9_007_199_260_000_000,
            'maximum_cost_nanodollars' => 9_007_199_260_000_000,
        ]);
        $this->assertSame('9007199.260000000', $large->toHistoryArray()['estimated_cost_usd']);
        $this->assertSame('9007199.260000000', $large->toHistoryArray()['maximum_cost_usd']);
    }

    public function test_default_serialization_uses_the_safe_history_projection(): void
    {
        $generation = Generation::factory()->for($this->user)->create([
            'estimated_cost_nanodollars' => 9_007_199_260_000_000,
            'maximum_cost_nanodollars' => 9_007_199_260_000_000,
        ]);
        $generation->load('user');

        $this->assertSame($generation->toHistoryArray(), $generation->toArray());
        $this->assertSame(
            $generation->toHistoryArray(),
            json_decode($generation->toJson(), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertArrayNotHasKey('user', $generation->toArray());
    }

    public function test_request_and_identity_fields_are_immutable_after_creation(): void
    {
        $generation = Generation::factory()->for($this->user)->create();
        $otherUser = User::factory()->create();

        $mutations = [
            'id' => (string) Str::ulid(),
            'user_id' => $otherUser->id,
            'submission_token' => (string) Str::uuid7(),
            'prompt' => 'A different prompt',
            'size' => GenerationSize::Portrait,
            'quality' => GenerationQuality::High,
            'reference_images' => ['https://example.com/new.png'],
            'quote_snapshot' => ['policy' => ['markup' => '6/4']],
            'estimated_cost_nanodollars' => 1,
            'maximum_cost_nanodollars' => 2,
            'created_at' => now()->subDay(),
        ];

        foreach ($mutations as $attribute => $value) {
            $fresh = Generation::query()->findOrFail($generation->id);
            $fresh->setAttribute($attribute, $value);

            try {
                $fresh->save();
                $this->fail("Expected immutable attribute [{$attribute}] to reject an update.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($attribute, $exception->getMessage());
            }
        }

        $row = DB::table('generations')->where('id', $generation->id)->first();

        $this->assertSame('A lighthouse', $row->prompt);
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('pending', $row->submission_status);
        $this->assertSame(80_000_000, $row->estimated_cost_nanodollars);
    }

    public function test_provider_and_submission_fields_cannot_be_mass_assigned_on_create(): void
    {
        $attempts = [
            ['provider_status' => ProviderGenerationStatus::Succeeded],
            ['provider_id' => 'pf_fabricated'],
            ['usage' => ['tokens' => 1]],
            ['submission_status' => GenerationSubmissionStatus::Uncertain],
            ['revision' => 1],
        ];

        foreach ($attempts as $attributes) {
            try {
                Generation::factory()->for($this->user)->create($attributes);
                $this->fail('Expected reserved generation attributes to be rejected.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('read-only', $exception->getMessage());
            }
        }

        $this->assertSame(0, Generation::query()->where('user_id', $this->user->id)->count());
    }

    public function test_provider_and_submission_fields_cannot_be_assigned_directly_on_create(): void
    {
        $attributes = [
            'user_id' => $this->user->id,
            'submission_token' => (string) Str::uuid7(),
            'prompt' => 'A lighthouse',
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'quote_snapshot' => ['policy' => ['markup' => '5/4']],
            'estimated_cost_nanodollars' => 1,
            'maximum_cost_nanodollars' => 1,
        ];

        $attempts = [
            'provider_status' => ProviderGenerationStatus::Succeeded,
            'provider_id' => 'pf_fabricated',
            'image_url' => 'https://example.com/fabricated.png',
            'usage' => ['tokens' => 1],
            'submission_status' => GenerationSubmissionStatus::Accepted,
            'revision' => 1,
        ];

        foreach ($attempts as $attribute => $value) {
            $generation = new Generation($attributes);
            $generation->setAttribute($attribute, $value);

            try {
                $generation->save();
                $this->fail("Expected reserved attribute [{$attribute}] to reject creation.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($attribute, $exception->getMessage());
            }
        }

        $this->assertDatabaseMissing('generations', ['submission_token' => $attributes['submission_token']]);
    }

    public function test_provider_and_submission_fields_cannot_be_updated_directly(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        $mutations = [
            'submission_status' => GenerationSubmissionStatus::Uncertain,
            'submission_attempts' => 1,
            'last_attempt_at' => now(),
            'next_attempt_at' => now(),
            'submission_error' => ['code' => 'timeout'],
            'provider_key_fingerprint' => str_repeat('a', 64),
            'provider_id' => 'pf_1',
            'provider_status' => ProviderGenerationStatus::Queued,
            'rates_snapshot' => ['rates' => []],
            'usage' => ['tokens' => 1],
            'image_url' => 'https://example.com/result.png',
            'image_url_expires_at' => now(),
            'provider_error' => ['code' => 'failed'],
            'provider_created_at' => now(),
            'provider_started_at' => now(),
            'provider_completed_at' => now(),
            'revision' => 1,
        ];

        foreach ($mutations as $attribute => $value) {
            $fresh = Generation::query()->findOrFail($generation->id);
            $fresh->setAttribute($attribute, $value);

            try {
                $fresh->save();
                $this->fail("Expected reserved attribute [{$attribute}] to reject an update.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($attribute, $exception->getMessage());
            }
        }

        $row = DB::table('generations')->where('id', $generation->id)->first();

        $this->assertSame('pending', $row->submission_status);
        $this->assertSame(0, $row->submission_attempts);
        $this->assertNull($row->provider_id);
        $this->assertNull($row->provider_status);
        $this->assertSame(0, $row->revision);
    }

    public function test_generation_model_deletion_is_refused(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        try {
            $generation->delete();
            $this->fail('Expected a generation record to be retained.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('retained', $exception->getMessage());
        }

        $this->assertTrue(Generation::query()->whereKey($generation->id)->exists());
    }

    public function test_default_state_machine_is_fresh_and_does_not_persist_its_mutations(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        $machine = $generation->stateMachine();

        $this->assertNull($machine->status());
        $this->assertTrue($machine->canProcess());
        $this->assertTrue($machine->canComplete());
        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applySucceeded());
        $this->assertTrue($machine->isSucceeded());

        $generation->refresh();

        $this->assertNull($generation->provider_status);
        $this->assertNull($generation->stateMachine()->status());
        $this->assertNotSame($machine, $generation->stateMachine());
    }

    public function test_state_machine_hydrates_from_the_persisted_provider_status(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        DB::table('generations')->where('id', $generation->id)->update([
            'provider_id' => 'pf_processing',
            'provider_status' => 'processing',
        ]);

        $generation->refresh();
        $machine = $generation->stateMachine();

        $this->assertSame(ProviderGenerationStatus::Processing, $machine->status());
        $this->assertFalse($machine->canProcess());
        $this->assertTrue($machine->canComplete());
        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applySucceeded());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());

        $generation->refresh();
        $this->assertSame(ProviderGenerationStatus::Processing, $generation->provider_status);
    }

    public function test_operator_database_deletion_retains_the_generation_without_an_owner(): void
    {
        $generation = Generation::factory()->for($this->user)->create();

        DB::table('users')->where('id', $this->user->id)->delete();
        $generation->refresh();

        $this->assertNull($generation->user_id);
        $this->assertNull($generation->user);
        $this->assertTrue(Generation::query()->whereKey($generation->id)->exists());
        $this->assertNull(User::query()->find($this->user->id));
    }

    public function test_submission_tokens_are_unique_per_owner_but_not_across_owners(): void
    {
        $token = (string) Str::uuid7();

        $this->assertDatabaseRejects(function () use ($token): void {
            Generation::factory()->for($this->user)->create(['submission_token' => $token]);
            Generation::factory()->for($this->user)->create(['submission_token' => $token]);
        }, '23505');

        $otherUser = User::factory()->create();

        $first = Generation::factory()->for($this->user)->create(['submission_token' => $token]);
        $second = Generation::factory()->for($otherUser)->create(['submission_token' => $token]);

        $this->assertSame($token, $first->submission_token);
        $this->assertSame($token, $second->submission_token);
    }

    public function test_provider_ids_are_unique_when_present(): void
    {
        $this->assertDatabaseRejects(function (): void {
            $this->insertRawGeneration(['provider_id' => 'pf_duplicate']);
            $this->insertRawGeneration(['provider_id' => 'pf_duplicate']);
        }, '23505');

        $this->insertRawGeneration();
        $this->insertRawGeneration();

        $this->assertSame(2, DB::table('generations')->whereNull('provider_id')->count());
    }

    public function test_model_rejects_invalid_request_and_money_inputs(): void
    {
        $tooManyReferences = array_fill(0, 5, 'https://example.com/reference.png');

        $cases = [
            ['user_id' => null],
            ['user_id' => 2_000_000_000],
            ['submission_token' => null],
            ['submission_token' => 'not-a-uuid'],
            ['prompt' => null],
            ['prompt' => ''],
            ['prompt' => '   '],
            ['prompt' => "bad\0prompt"],
            ['prompt' => str_repeat('😀', 4001)],
            ['reference_images' => 'https://example.com/reference.png'],
            ['reference_images' => $tooManyReferences],
            ['reference_images' => ['ftp://example.com/reference.png']],
            ['reference_images' => ['not a url']],
            ['reference_images' => [123]],
            ['quote_snapshot' => []],
            ['quote_snapshot' => ['a', 'b']],
            ['quote_snapshot' => 'not-an-object'],
            ['estimated_cost_nanodollars' => -1],
            ['estimated_cost_nanodollars' => 1.5],
            ['estimated_cost_nanodollars' => '100'],
            ['maximum_cost_nanodollars' => -1],
            ['estimated_cost_nanodollars' => 2, 'maximum_cost_nanodollars' => 1],
            ['estimated_cost_nanodollars' => 72_206_250],
            ['maximum_cost_nanodollars' => 90_000_001],
        ];

        foreach ($cases as $attributes) {
            $this->assertModelRejects($attributes);
        }
    }

    public function test_model_rejects_unsupported_enum_values(): void
    {
        try {
            Generation::factory()->for($this->user)->make(['size' => '2048x2048']);
            $this->fail('Expected an unsupported size to be rejected by the enum cast.');
        } catch (ValueError $exception) {
            $this->assertStringContainsString('2048x2048', $exception->getMessage());
        }

        $this->expectException(ValueError::class);
        Generation::factory()->for($this->user)->make(['quality' => 'ultra']);
    }

    public function test_database_rejects_invalid_enum_prompt_money_and_json_shapes(): void
    {
        $cases = [
            ['size' => '2048x2048'],
            ['quality' => 'ultra'],
            ['submission_status' => 'submitted'],
            ['provider_status' => 'pending'],
            ['prompt' => ''],
            ['prompt' => str_repeat('a', 4001)],
            ['estimated_cost_nanodollars' => -1],
            ['maximum_cost_nanodollars' => -1],
            ['estimated_cost_nanodollars' => 2, 'maximum_cost_nanodollars' => 1],
            ['estimated_cost_nanodollars' => 72_206_250],
            ['maximum_cost_nanodollars' => 90_000_001],
            ['submission_attempts' => -1],
            ['revision' => -1],
            ['reference_images' => json_encode('not-an-array', JSON_THROW_ON_ERROR)],
            ['reference_images' => json_encode(array_fill(0, 5, 'https://example.com/reference.png'), JSON_THROW_ON_ERROR)],
            ['reference_images' => '{}'],
            ['quote_snapshot' => '[]'],
            ['quote_snapshot' => '{}'],
            ['quote_snapshot' => '"not-an-object"'],
            ['submission_error' => '[]'],
            ['submission_error' => '5'],
            ['rates_snapshot' => '[]'],
            ['usage' => '[]'],
            ['provider_error' => '[]'],
        ];

        foreach ($cases as $overrides) {
            $this->assertRawInsertRejected($overrides);
        }
    }

    public function test_database_requires_provider_identity_for_accepted_submissions(): void
    {
        $this->assertRawInsertRejected(['submission_status' => 'accepted']);

        $this->insertRawGeneration([
            'submission_status' => 'accepted',
            'provider_id' => 'pf_accepted',
            'provider_status' => 'queued',
        ]);

        $this->assertDatabaseHas('generations', [
            'submission_status' => 'accepted',
            'provider_id' => 'pf_accepted',
            'provider_status' => 'queued',
        ]);
    }

    public function test_generations_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('generations'));

        $migration = require database_path('migrations/2026_11_17_033944_create_generations_table.php');
        $financial = require database_path('migrations/2026_11_17_120000_create_generation_charges_and_outbox_tables.php');
        $ledger = require database_path('migrations/2026_11_17_160000_create_credit_ledger_entries_table.php');

        $ledger->down();
        $financial->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('generations'));

        $migration->up();
        $financial->up();
        $ledger->up();
        $this->assertTrue(Schema::hasTable('generations'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertRawGeneration(array $overrides = []): void
    {
        DB::table('generations')->insert(array_merge([
            'id' => (string) Str::ulid(),
            'user_id' => $this->user->id,
            'submission_token' => (string) Str::uuid7(),
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => '[]',
            'quote_snapshot' => json_encode(['policy' => ['markup' => '5/4']], JSON_THROW_ON_ERROR),
            'estimated_cost_nanodollars' => 80_000_000,
            'maximum_cost_nanodollars' => 90_000_000,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function assertRawInsertRejected(array $overrides, string $sqlState = '23514'): void
    {
        $this->assertDatabaseRejects(
            fn () => $this->insertRawGeneration($overrides),
            $sqlState,
        );
    }

    private function assertDatabaseRejects(Closure $callback, string $sqlState): void
    {
        try {
            DB::transaction($callback);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, (string) $exception->getCode());

            return;
        }

        $this->fail('Expected the database to reject the operation.');
    }

    /** @param  array<string, mixed>  $overrides */
    private function assertModelRejects(array $overrides): void
    {
        $generation = Generation::factory()->for($this->user)->make($overrides);

        try {
            $generation->save();
            $this->fail('Expected model validation to reject the generation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
