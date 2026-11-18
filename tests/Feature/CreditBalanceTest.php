<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\CreditEntryType;
use App\Enums\GenerationSubmissionStatus;
use App\Exceptions\AccountHasGenerations;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Http\Integrations\Pixelforge\Requests\CreateGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\SubmitGenerationJob;
use App\Models\CreditLedgerEntry;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\GenerationWorkflowFixture;
use Tests\TestCase;

final class CreditBalanceTest extends TestCase
{
    use GenerationWorkflowFixture, RefreshDatabase;

    private const MAXIMUM = 90_000_000;

    private const CHARGE = 80_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pixelforge.api_key' => 'workflow-test-key',
            'services.pixelforge.webhook_secret' => 'workflow-test-secret',
            'cache.default' => 'array',
        ]);
        Route::post('/_test/pixelforge-webhook', PixelforgeWebhookController::class);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    /** @return array<string, array{int, bool}> */
    public static function affordabilityBoundary(): array
    {
        return [
            'balance equals the maximum' => [self::MAXIMUM, true],
            'one cent short' => [self::MAXIMUM - 10_000_000, false],
            'zero balance' => [0, false],
        ];
    }

    #[DataProvider('affordabilityBoundary')]
    public function test_a_request_needs_available_credit_for_its_quoted_maximum(int $balance, bool $accepted): void
    {
        $user = $this->userWithCredit($balance);
        $response = $this->requestGeneration($user);

        if ($accepted) {
            $response->assertRedirect(route('generations.index'));
            $generation = Generation::query()->sole();
            $hold = CreditLedgerEntry::query()->where('type', 'hold')->sole();
            $this->assertSame($generation->id, $hold->generation_id);
            $this->assertSame(self::MAXIMUM, $hold->amount_nanodollars);
            $this->assertSame(0, CreditLedgerEntry::availableFor($user));
            $this->assertSame(self::MAXIMUM, CreditLedgerEntry::heldFor($user));
            Queue::assertPushed(SubmitGenerationJob::class, 1);

            return;
        }

        $response->assertStatus(422)->assertJsonValidationErrors(['credits']);
        $this->assertStringContainsString('can cost up to $0.09', $response->json('errors.credits.0'));
        $this->assertStringContainsString('Nothing was created or charged', $response->json('errors.credits.0'));
        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(0, CreditLedgerEntry::query()->where('type', '<>', 'purchase')->count());
        $this->assertSame($balance, CreditLedgerEntry::availableFor($user));
        Queue::assertNothingPushed();
    }

    public function test_an_inertia_form_submission_gets_the_credit_error_back_on_the_form(): void
    {
        $user = $this->userWithCredit(0);

        $this->actingAs($user)->from(route('dashboard'))
            ->post(route('generations.store'), $this->generationPayload())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('credits');

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_a_retried_submission_returns_the_original_without_a_second_hold(): void
    {
        $user = $this->userWithCredit(self::MAXIMUM);
        $payload = $this->generationPayload();

        $this->requestGeneration($user, $payload)->assertRedirect(route('generations.index'));
        $this->requestGeneration($user, $payload)->assertRedirect(route('generations.index'));

        $this->assertDatabaseCount('generations', 1);
        $this->assertSame(1, CreditLedgerEntry::query()->where('type', 'hold')->count());
    }

    public function test_success_releases_the_hold_and_debits_the_charge_once_despite_duplicates(): void
    {
        $user = $this->userWithCredit(1_000_000_000);
        $generation = $this->requestedGeneration($user);
        $succeeded = $this->providerPayload($generation, 'succeeded');
        Saloon::fake([CreateGeneration::class => MockResponse::make($succeeded, 202)]);

        $this->submit($generation);
        foreach (['evt_done1', 'evt_done2'] as $id) {
            $this->receive($this->eventPayload($succeeded, $id))->assertNoContent();
            $this->processEvent($id);
            $this->processEvent($id);
        }
        $this->receive($this->eventPayload($this->providerPayload($generation, 'processing'), 'evt_late'))->assertNoContent();
        $this->processEvent('evt_late');
        app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($succeeded), hash('sha256', 'workflow-test-key'));

        $this->assertLedger($generation, hold: self::MAXIMUM, release: self::MAXIMUM, debit: self::CHARGE);
        $this->assertSame(self::CHARGE, $generation->charge()->sole()->customer_cost_nanodollars);
        $this->assertSame(1_000_000_000 - self::CHARGE, CreditLedgerEntry::availableFor($user));
        $this->assertSame(0, CreditLedgerEntry::heldFor($user));
    }

    public function test_success_observed_before_any_queued_state_settles_once(): void
    {
        $user = $this->userWithCredit(1_000_000_000);
        $generation = $this->requestedGeneration($user);
        Saloon::fake([CreateGeneration::class => MockResponse::make($this->providerPayload($generation, 'queued'), 202)]);
        $this->submit($generation);

        foreach (['succeeded', 'processing', 'queued'] as $status) {
            $this->receive($this->eventPayload($this->providerPayload($generation, $status), 'evt_'.$status))->assertNoContent();
            $this->processEvent('evt_'.$status);
        }

        $this->assertLedger($generation, hold: self::MAXIMUM, release: self::MAXIMUM, debit: self::CHARGE);
    }

    public function test_provider_failure_releases_the_whole_hold_without_a_debit(): void
    {
        $user = $this->userWithCredit(1_000_000_000);
        $generation = $this->requestedGeneration($user);
        Saloon::fake([CreateGeneration::class => MockResponse::make($this->providerPayload($generation, 'processing'), 202)]);
        $this->submit($generation);
        $this->assertLedger($generation, hold: self::MAXIMUM);

        $failed = $this->providerPayload($generation, 'failed');
        foreach (['evt_fail1', 'evt_fail2'] as $id) {
            $this->receive($this->eventPayload($failed, $id))->assertNoContent();
            $this->processEvent($id);
        }

        $this->assertLedger($generation, hold: self::MAXIMUM, release: self::MAXIMUM);
        $this->assertDatabaseCount('generation_charges', 0);
        $this->assertSame(1_000_000_000, CreditLedgerEntry::availableFor($user));
    }

    public function test_a_definitive_rejection_releases_the_hold(): void
    {
        $user = $this->userWithCredit(1_000_000_000);
        $generation = $this->requestedGeneration($user);
        Saloon::fake([CreateGeneration::class => MockResponse::make(['error' => ['code' => 'content_policy_violation']], 400)]);

        $this->submit($generation);
        $this->submit($generation);

        $this->assertSame(GenerationSubmissionStatus::Rejected, $generation->fresh()->submission_status);
        $this->assertLedger($generation, hold: self::MAXIMUM, release: self::MAXIMUM);
        $this->assertSame(1_000_000_000, CreditLedgerEntry::availableFor($user));
    }

    public function test_uncertain_and_paused_submissions_keep_their_hold_until_the_outcome_is_known(): void
    {
        $user = $this->userWithCredit(1_000_000_000);
        $generation = $this->requestedGeneration($user);
        Saloon::fake([
            MockResponse::make()->throw(fn (PendingRequest $pending) => new FatalRequestException(new \RuntimeException('simulated timeout'), $pending)),
            MockResponse::make(['error' => ['code' => 'content_policy_violation']], 400),
        ]);

        $this->submit($generation);
        $this->assertSame(GenerationSubmissionStatus::Uncertain, $generation->fresh()->submission_status);
        $this->assertLedger($generation, hold: self::MAXIMUM);

        $this->travel(20)->seconds();
        $this->submit($generation);
        $paused = $generation->fresh();
        $this->assertSame(GenerationSubmissionStatus::Uncertain, $paused->submission_status);
        $this->assertFalse($paused->submission_error['retryable']);
        $this->assertLedger($generation, hold: self::MAXIMUM);
        $this->assertSame(1_000_000_000 - self::MAXIMUM, CreditLedgerEntry::availableFor($user));

        $this->receive($this->eventPayload($this->providerPayload($generation, 'succeeded'), 'evt_found'))->assertNoContent();
        $this->processEvent('evt_found');
        $this->assertLedger($generation, hold: self::MAXIMUM, release: self::MAXIMUM, debit: self::CHARGE);
    }

    public function test_an_actual_charge_above_the_hold_is_debited_in_full_and_blocks_new_requests(): void
    {
        $user = $this->userWithCredit(self::MAXIMUM);
        $generation = $this->requestedGeneration($user);
        $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '12.50'];
        Saloon::fake([CreateGeneration::class => MockResponse::make($this->providerPayload($generation, 'succeeded', ['rates' => $rates]), 202)]);

        $this->submit($generation);

        $this->assertLedger($generation, hold: self::MAXIMUM, release: self::MAXIMUM, debit: 130_000_000);
        $this->assertSame(130_000_000, $generation->charge()->sole()->customer_cost_nanodollars);
        $this->assertSame(-40_000_000, CreditLedgerEntry::availableFor($user));
        $this->assertSame(['available_usd' => '-0.040000000', 'held_usd' => '0.000000000'], CreditLedgerEntry::balanceFor($user));

        $this->requestGeneration($user)->assertStatus(422)->assertJsonValidationErrors(['credits']);
        $this->assertStringContainsString('available balance is -$0.04', $this->requestGeneration($user)->json('errors.credits.0'));
        $this->assertDatabaseCount('generations', 1);
    }

    public function test_generations_from_before_the_ledger_settle_without_retroactive_entries(): void
    {
        $generation = Generation::factory()->create();
        DB::transaction(fn () => $generation->prepareSubmission(hash('sha256', 'workflow-test-key')));
        $succeeded = ProviderGeneration::fromArray($this->providerPayload($generation, 'succeeded'));

        app(ApplyProviderGeneration::class)->execute($generation->id, $succeeded, hash('sha256', 'workflow-test-key'));
        app(ApplyProviderGeneration::class)->execute($generation->id, $succeeded, hash('sha256', 'workflow-test-key'));

        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('credit_ledger_entries', 0);
        $this->assertSame(0, CreditLedgerEntry::availableFor($generation->user));
    }

    public function test_the_ledger_migration_adds_no_entries_for_existing_settled_generations(): void
    {
        $generation = Generation::factory()->create();
        DB::transaction(fn () => $generation->prepareSubmission(hash('sha256', 'workflow-test-key')));
        app(ApplyProviderGeneration::class)->execute($generation->id, ProviderGeneration::fromArray($this->providerPayload($generation, 'succeeded')), hash('sha256', 'workflow-test-key'));
        $migration = require database_path('migrations/2026_11_17_160000_create_credit_ledger_entries_table.php');

        $migration->down();
        $migration->up();

        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('credit_ledger_entries', 0);
        $this->assertSame(0, CreditLedgerEntry::availableFor($generation->user));
    }

    public function test_a_purchase_token_used_twice_credits_once(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();

        $this->actingAs($user)->post(route('credits.purchases.store'), ['package' => 'usd_10', 'idempotency_token' => $token])
            ->assertRedirect(route('credits.index'))
            ->assertInertiaFlash('toast.message', 'Added $10.00 of demo credit. No payment was taken.');
        $this->actingAs($user)->post(route('credits.purchases.store'), ['package' => 'usd_10', 'idempotency_token' => $token])
            ->assertRedirect(route('credits.index'))
            ->assertInertiaFlash('toast.message', 'This purchase was already applied. Nothing more was added.');

        $this->assertSame(1, CreditLedgerEntry::query()->count());
        $this->assertSame(10_000_000_000, CreditLedgerEntry::availableFor($user));
    }

    public function test_a_purchase_token_reused_for_another_package_is_a_conflict(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();
        $this->actingAs($user)->post(route('credits.purchases.store'), ['package' => 'usd_5', 'idempotency_token' => $token]);

        $this->actingAs($user)->postJson(route('credits.purchases.store'), ['package' => 'usd_25', 'idempotency_token' => $token])
            ->assertStatus(422)->assertJsonValidationErrors(['idempotency_token']);

        $this->assertSame(5_000_000_000, CreditLedgerEntry::availableFor($user));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPurchases(): array
    {
        return [
            'unknown package' => [['package' => 'usd_1000000'], 'package'],
            'client amount instead of package' => [['package' => null, 'amount_nanodollars' => 1_000_000_000], 'package'],
            'missing token' => [['idempotency_token' => null], 'idempotency_token'],
            'token is not a uuid' => [['idempotency_token' => 'retry-1'], 'idempotency_token'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPurchases')]
    public function test_invalid_purchases_add_nothing(array $overrides, string $field): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('credits.purchases.store'), array_replace(['package' => 'usd_5', 'idempotency_token' => (string) Str::uuid()], $overrides))
            ->assertStatus(422)->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('credit_ledger_entries', 0);
    }

    public function test_purchases_require_an_authenticated_verified_user_and_are_rate_limited(): void
    {
        $payload = fn (): array => ['package' => 'usd_5', 'idempotency_token' => (string) Str::uuid()];

        $this->post(route('credits.purchases.store'), $payload())->assertRedirect(route('login'));
        $this->get(route('credits.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->postJson(route('credits.purchases.store'), $payload())->assertForbidden();
        $this->assertDatabaseCount('credit_ledger_entries', 0);

        $user = User::factory()->create();
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($user)->post(route('credits.purchases.store'), $payload())->assertRedirect(route('credits.index'));
        }
        $this->actingAs($user)->post(route('credits.purchases.store'), $payload())->assertTooManyRequests();
        $this->assertSame(10, CreditLedgerEntry::query()->where('user_id', $user->id)->count());
    }

    public function test_balances_tokens_and_activity_are_scoped_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $token = (string) Str::uuid();

        $this->actingAs($owner)->post(route('credits.purchases.store'), ['package' => 'usd_5', 'idempotency_token' => $token]);
        $this->actingAs($other)->post(route('credits.purchases.store'), ['package' => 'usd_25', 'idempotency_token' => $token]);

        $this->actingAs($owner)->get(route('credits.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Credits')
            ->where('credits', ['available_usd' => '5.000000000', 'held_usd' => '0.000000000'])
            ->has('entries', 1)
            ->where('entries.0.type', 'purchase')
            ->where('entries.0.amount_usd', '5.000000000')
            ->missing('entries.0.idempotency_key')
            ->missing('entries.0.user_id')
            ->has('packages', 3)
            ->where('packages.0', ['id' => 'usd_5', 'amount_usd' => '5.000000000']));
        $this->actingAs($other)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('credits.available_usd', '25.000000000'));
    }

    public function test_activity_hides_released_holds_but_keeps_active_holds_and_charges(): void
    {
        $user = $this->userWithCredit(10_000_000_000);
        $succeeded = $this->requestedGeneration($user);
        Saloon::fake([CreateGeneration::class => MockResponse::make($this->providerPayload($succeeded, 'succeeded'), 202)]);
        $this->submit($succeeded);
        $failed = $this->requestedGeneration($user);
        Saloon::fake([CreateGeneration::class => MockResponse::make($this->providerPayload($failed, 'failed'), 202)]);
        $this->submit($failed);
        $inProgress = $this->requestedGeneration($user);

        $this->assertSame(7, CreditLedgerEntry::query()->where('user_id', $user->id)->count());
        $this->actingAs($user)->get(route('credits.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries', 3)
            ->where('entries.0.type', 'hold')
            ->where('entries.0.generation_id', $inProgress->id)
            ->where('entries.1.type', 'debit')
            ->where('entries.1.generation_id', $succeeded->id)
            ->where('entries.1.detail', 'A lighthouse')
            ->where('entries.2.detail', null)
            ->where('entries.2.type', 'purchase'));
    }

    public function test_activity_shows_a_short_single_line_prompt_excerpt(): void
    {
        $user = $this->userWithCredit(10_000_000_000);
        $prompt = "A misty  harbour\nat dawn, ".str_repeat('with gulls ', 40);
        $this->requestGeneration($user, [...$this->generationPayload(), 'prompt' => $prompt])->assertRedirect(route('generations.index'));

        $excerpt = $this->actingAs($user)->get(route('credits.index'))->viewData('page')['props']['entries'][0]['detail'];
        $this->assertStringStartsWith('A misty harbour at dawn, with gulls', $excerpt);
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertLessThanOrEqual(61, mb_strlen($excerpt));
        $this->assertStringNotContainsString("\n", $excerpt);
    }

    public function test_operator_adjustments_set_the_available_balance_in_either_direction_once(): void
    {
        $user = $this->userWithCredit(2_670_000_000);

        $this->artisan('credits:adjust', ['email' => $user->email, '--to' => '0.40', '--reason' => 'Testing a low balance'])
            ->expectsOutput('Available balance $2.67 -> $0.40 (debit_adjustment $2.27).')->assertSuccessful();
        $this->artisan('credits:adjust', ['email' => $user->email, '--to' => '0.40', '--reason' => 'Testing a low balance'])
            ->expectsOutput('Available balance is already $0.40; nothing recorded.')->assertSuccessful();
        $this->artisan('credits:adjust', ['email' => $user->email, '--to' => '5', '--reason' => 'Goodwill credit'])
            ->expectsOutput('Available balance $0.40 -> $5.00 (credit_adjustment $4.60).')->assertSuccessful();

        $this->assertSame(5_000_000_000, CreditLedgerEntry::availableFor($user));
        $this->assertSame(3, CreditLedgerEntry::query()->where('user_id', $user->id)->count());
        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.type', 'credit_adjustment')
            ->where('entries.0.amount_usd', '4.600000000')
            ->where('entries.0.detail', 'Goodwill credit')
            ->where('entries.1.type', 'debit_adjustment')
            ->where('entries.1.amount_usd', '2.270000000')
            ->where('entries.1.detail', 'Testing a low balance'));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalidAdjustments(): array
    {
        return [
            'missing reason' => [['--to' => '0.40']],
            'blank reason' => [['--to' => '0.40', '--reason' => '   ']],
            'negative target' => [['--to' => '-1', '--reason' => 'x']],
            'sub-cent target' => [['--to' => '0.405', '--reason' => 'x']],
            'not a number' => [['--to' => 'lots', '--reason' => 'x']],
            'above the adjustment limit' => [['--to' => '1011', '--reason' => 'x']],
            'unknown user' => [['email' => 'nobody@example.invalid', '--to' => '0.40', '--reason' => 'x']],
        ];
    }

    /** @param array<string, string> $options */
    #[DataProvider('invalidAdjustments')]
    public function test_invalid_adjustments_record_nothing(array $options): void
    {
        $user = $this->userWithCredit(1_000_000_000);

        $this->artisan('credits:adjust', ['email' => $user->email, ...$options])->assertFailed();

        $this->assertSame(1, CreditLedgerEntry::query()->where('user_id', $user->id)->count());
        $this->assertSame(1_000_000_000, CreditLedgerEntry::availableFor($user));
    }

    public function test_the_database_requires_adjustments_to_carry_a_reason_and_no_generation(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create();
        $row = fn (array $overrides): array => array_replace(['user_id' => $user->id, 'type' => 'debit_adjustment', 'amount_nanodollars' => 10_000_000, 'generation_id' => null, 'idempotency_key' => null, 'reason' => 'Operator correction', 'created_at' => now()], $overrides);

        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['reason' => null])), 'an adjustment without a reason');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['reason' => '  '])), 'an adjustment with a blank reason');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['generation_id' => $generation->id])), 'an adjustment tied to a generation');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['amount_nanodollars' => 0])), 'a zero adjustment');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'purchase', 'idempotency_key' => (string) Str::uuid()])), 'a purchase with a reason');

        DB::table('credit_ledger_entries')->insert($row([]));
        $this->assertSame(-10_000_000, CreditLedgerEntry::availableFor($user));
    }

    public function test_the_adjustment_migration_refuses_to_roll_back_over_adjustments(): void
    {
        $user = $this->userWithCredit(1_000_000_000);
        $migration = require database_path('migrations/2026_11_17_170000_add_credit_adjustments_to_ledger.php');
        DB::transaction(fn () => CreditLedgerEntry::adjust($user, -10_000_000, 'Operator correction'));

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_the_adjustment_migration_is_reversible_without_adjustments(): void
    {
        $this->userWithCredit(1_000_000_000);
        $migration = require database_path('migrations/2026_11_17_170000_add_credit_adjustments_to_ledger.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('credit_ledger_entries', 'reason'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('credit_ledger_entries', 'reason'));
        $this->assertSame(1, CreditLedgerEntry::query()->count());
    }

    public function test_the_quote_reports_affordability_at_the_boundary_without_creating_anything(): void
    {
        $this->fakePricing();
        $quote = ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium'];

        $this->actingAs($this->userWithCredit(self::MAXIMUM))->postJson(route('generations.quote'), $quote)->assertOk()->assertJsonPath('affordable', true);
        $this->actingAs($this->userWithCredit(self::MAXIMUM - 10_000_000))->postJson(route('generations.quote'), $quote)->assertOk()->assertJsonPath('affordable', false);

        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(0, CreditLedgerEntry::query()->where('type', '<>', 'purchase')->count());
    }

    public function test_credit_evidence_blocks_account_deletion(): void
    {
        $user = $this->userWithCredit(5_000_000_000);

        $this->actingAs($user)->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->expectException(AccountHasGenerations::class);
        $user->delete();
    }

    public function test_ledger_entries_are_append_only_in_the_model_and_the_database(): void
    {
        $user = $this->userWithCredit(5_000_000_000);
        $entry = CreditLedgerEntry::query()->sole();

        foreach ([fn () => $entry->delete(), fn () => tap($entry, fn ($e) => $e->amount_nanodollars = 1)->save()] as $mutation) {
            try {
                $mutation();
                $this->fail('Ledger entries must be immutable.');
            } catch (LogicException) {
            }
        }
        foreach (['UPDATE credit_ledger_entries SET amount_nanodollars = 1', 'DELETE FROM credit_ledger_entries'] as $sql) {
            $this->assertDatabaseRejects(fn () => DB::statement($sql));
        }
        $this->assertSame(5_000_000_000, CreditLedgerEntry::availableFor($user));
    }

    public function test_database_constraints_back_the_ledger_invariants(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create();
        $row = fn (array $overrides): array => array_replace(['user_id' => $user->id, 'type' => 'hold', 'amount_nanodollars' => self::MAXIMUM, 'generation_id' => $generation->id, 'idempotency_key' => null, 'created_at' => now()], $overrides);

        DB::table('credit_ledger_entries')->insert($row([]));
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row([])), 'a second hold for one generation');
        DB::table('credit_ledger_entries')->insert($row(['type' => 'debit', 'amount_nanodollars' => self::CHARGE]));
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'debit', 'amount_nanodollars' => self::CHARGE])), 'a second debit');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'release', 'amount_nanodollars' => 90_000_001])), 'a non-cent amount');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'refund'])), 'an unknown type');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'release', 'generation_id' => null])), 'a generation entry without its generation');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'purchase', 'generation_id' => null])), 'a purchase without an idempotency key');
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'purchase', 'generation_id' => null, 'idempotency_key' => (string) Str::uuid(), 'amount_nanodollars' => 0])), 'a zero purchase');

        $token = (string) Str::uuid();
        DB::table('credit_ledger_entries')->insert($row(['type' => 'purchase', 'generation_id' => null, 'idempotency_key' => $token, 'amount_nanodollars' => 10_000_000]));
        $this->assertDatabaseRejects(fn () => DB::table('credit_ledger_entries')->insert($row(['type' => 'purchase', 'generation_id' => null, 'idempotency_key' => $token, 'amount_nanodollars' => 10_000_000])), 'a reused purchase token');
    }

    /** @return array<string, array{int}> */
    public static function invalidPurchaseAmounts(): array
    {
        return ['zero' => [0], 'negative' => [-10_000_000], 'fraction of a cent' => [10_000_001], 'above the limit' => [CreditLedgerEntry::MAX_PURCHASE_NANODOLLARS + 10_000_000]];
    }

    #[DataProvider('invalidPurchaseAmounts')]
    public function test_the_model_accepts_only_bounded_whole_cent_purchases(int $amount): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        DB::transaction(fn () => CreditLedgerEntry::purchase($user, $amount, (string) Str::uuid()));
    }

    private function assertLedger(Generation $generation, int $hold, ?int $release = null, ?int $debit = null): void
    {
        $entries = CreditLedgerEntry::query()->where('generation_id', $generation->id)->get()
            ->mapWithKeys(fn (CreditLedgerEntry $entry): array => [$entry->type->value => $entry->amount_nanodollars])->all();
        $expected = array_filter([CreditEntryType::Hold->value => $hold, CreditEntryType::Release->value => $release, CreditEntryType::Debit->value => $debit], fn (?int $amount): bool => $amount !== null);
        ksort($entries);
        ksort($expected);
        $this->assertSame($expected, $entries);
        $this->assertSame(count($expected), CreditLedgerEntry::query()->where('generation_id', $generation->id)->count());
    }

    private function assertDatabaseRejects(callable $statement, string $case = 'statement'): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("The database accepted {$case}.");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function userWithCredit(int $nanodollars): User
    {
        return $nanodollars > 0 ? User::factory()->withCredit($nanodollars)->create() : User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function generationPayload(): array
    {
        return ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium', 'submission_token' => (string) Str::uuid7()];
    }

    /** @param array<string, mixed>|null $payload */
    private function requestGeneration(User $user, ?array $payload = null): TestResponse
    {
        $this->fakePricing();

        return $this->actingAs($user)->postJson(route('generations.store'), $payload ?? $this->generationPayload());
    }

    private function requestedGeneration(User $user): Generation
    {
        $this->requestGeneration($user)->assertRedirect(route('generations.index'));

        return Generation::query()->forUser($user)->latest()->firstOrFail();
    }

    private function fakePricing(): void
    {
        Saloon::fake([GetPricing::class => MockResponse::make((string) file_get_contents(base_path('tests/Fixtures/pixelforge-pricing.json')), 200, ['Content-Type' => 'application/json'])]);
    }

    private function submit(Generation $generation): void
    {
        app()->call([new SubmitGenerationJob($generation->id), 'handle']);
    }

    private function processEvent(string $id): void
    {
        app()->call([new ProcessWebhookEventJob($id), 'handle']);
    }

    /** @param array<string, mixed> $payload */
    private function receive(array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = now()->timestamp;

        return $this->call('POST', '/_test/pixelforge-webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'workflow-test-secret'),
            'HTTP_PIXELFORGE_EVENT_ID' => $payload['id'],
            'HTTP_PIXELFORGE_EVENT_TYPE' => $payload['type'],
        ], content: $raw);
    }
}
