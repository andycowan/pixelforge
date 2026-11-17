<?php

namespace Tests\Feature;

use App\Actions\Generations\RequestGeneration;
use App\Http\Integrations\Pixelforge\Requests\CreateGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class RequestGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-request-generation-key-never-live';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.pixelforge.api_key' => self::API_KEY]);
        config(['cache.default' => 'array']);

        // Submission is queued work. faking the queue keeps the job from
        // contacting a provider inside the HTTP request under test.
        Queue::fake();

        Saloon::fake([
            GetPricing::class => $this->pricingResponse(),
        ]);

        $this->user = User::factory()->create();
    }

    public function test_guests_are_redirected_to_login_and_nothing_is_submitted(): void
    {
        $this->post(route('generations.store'), $this->payload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('generations', 0);
        Saloon::assertNothingSent();
    }

    public function test_quote_previews_do_not_consume_the_submission_rate_limit(): void
    {
        // Live previews fire on every debounced edit; they must not lock out Generate.
        for ($request = 1; $request <= 11; $request++) {
            $this->actingAs($this->user)
                ->postJson(route('generations.quote'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium'])
                ->assertOk();
        }

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->payload())
            ->assertRedirect(route('generations.index'));

        $this->assertDatabaseCount('generations', 1);
    }

    public function test_unverified_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->postJson(route('generations.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('generations', 0);
        Saloon::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_quote_rules_are_reused_and_the_submission_token_is_required(
        array $overrides,
        string $field,
    ): void {
        $this->actingAs($this->user)
            ->postJson(route('generations.store'), $this->payload($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('generations', 0);
        Saloon::assertNothingSent();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing token' => [['submission_token' => null], 'submission_token'],
            'token is not a uuid' => [['submission_token' => 'not-a-uuid'], 'submission_token'],
            'blank prompt' => [['prompt' => "   \n  "], 'prompt'],
            // Laravel trims a trailing NUL like other whitespace; an interior
            // NUL is still unsupported and must fail as a prompt error.
            'prompt contains a NUL byte' => [['prompt' => "A light\0house"], 'prompt'],
            'prompt too long' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
            'unsupported size' => [['size' => '2048x2048'], 'size'],
            'unsupported quality' => [['quality' => 'ultra'], 'quality'],
            'too many references' => [[
                'reference_images' => [
                    'https://example.com/1.png',
                    'https://example.com/2.png',
                    'https://example.com/3.png',
                    'https://example.com/4.png',
                    'https://example.com/5.png',
                ],
            ], 'reference_images'],
            'reference is not a url' => [['reference_images' => ['not-a-url']], 'reference_images.0'],
        ];
    }

    public function test_a_valid_request_persists_the_owned_request_and_the_server_quote(): void
    {
        $other = User::factory()->create();
        $payload = $this->payload([
            'reference_images' => ['https://example.com/first.png', 'https://example.com/first.png'],
            // Client-supplied money, ownership and quotes have no rules and are ignored.
            'user_id' => $other->id,
            'estimated_cost_nanodollars' => 1,
            'estimated_cost_usd' => '0.000000001',
            'quote_snapshot' => ['rates' => ['input_text_tokens' => '0.0001']],
        ]);

        $this->actingAs($this->user)
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();

        $this->assertSame($this->user->id, $generation->user_id);
        $this->assertSame($payload['submission_token'], $generation->submission_token);
        $this->assertSame('A lighthouse', $generation->prompt);
        $this->assertSame('1024x1024', $generation->size->value);
        $this->assertSame('medium', $generation->quality->value);

        // Reference order and duplicates are preserved: they affect billing.
        $this->assertSame(
            ['https://example.com/first.png', 'https://example.com/first.png'],
            $generation->reference_images,
        );

        // The server's quote wins, not the browser's numbers. Two reference
        // images add 2 x 765 image tokens at $10/million:
        // estimate (57,765,000 + 15,300,000) x 5/4 = 91,331,250
        // maximum  (69,020,000 + 15,300,000) x 5/4 = 105,400,000
        $this->assertSame(91_331_250, $generation->estimated_cost_nanodollars);
        $this->assertSame(105_400_000, $generation->maximum_cost_nanodollars);
        $this->assertNotSame(['input_text_tokens' => '0.0001'], $generation->quote_snapshot['rates']);
        $this->assertArrayHasKey('rates', $generation->quote_snapshot);
        $this->assertArrayHasKey('tokens', $generation->quote_snapshot);

        // New requests are pending and unobserved, with a frozen key identity.
        $this->assertSame('pending', $generation->submission_status->value);
        $this->assertSame(0, $generation->submission_attempts);
        $this->assertNull($generation->provider_id);
        $this->assertNull($generation->provider_status);
        $this->assertSame(hash('sha256', self::API_KEY), $generation->provider_key_fingerprint);

        $this->assertDatabaseCount('generations', 1);
    }

    public function test_the_request_is_durable_with_its_fingerprint_before_dispatch(): void
    {
        $captured = [];

        $this->mock(Dispatcher::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('dispatch')->once()->andReturnUsing(function (object $job) use (&$captured): void {
                $captured = [
                    'job' => $job,
                    'row' => DB::table('generations')->first(),
                ];
            });
        });

        $token = (string) Str::uuid7();

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->payload(['submission_token' => $token]))
            ->assertRedirect(route('generations.index'));

        $this->assertInstanceOf(SubmitGenerationJob::class, $captured['job']);
        $this->assertSame($captured['job']->generationId, $captured['row']->id);
        $this->assertSame($token, $captured['row']->submission_token);
        $this->assertSame('pending', $captured['row']->submission_status);
        $this->assertSame('A lighthouse', $captured['row']->prompt);
        $this->assertSame(hash('sha256', self::API_KEY), $captured['row']->provider_key_fingerprint);
    }

    public function test_a_dispatch_failure_keeps_the_committed_request(): void
    {
        Log::spy();

        $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue backend unavailable'));
        });

        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->payload())
            ->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();

        $this->assertSame('pending', $generation->submission_status->value);
        $this->assertSame(hash('sha256', self::API_KEY), $generation->provider_key_fingerprint);
        $this->assertSame(72_206_250, $generation->estimated_cost_nanodollars);

        // The failure is reported for operators; scheduled recovery rescues the row.
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_repeating_the_same_token_and_options_returns_the_original_without_requoting(): void
    {
        $token = (string) Str::uuid7();
        $payload = $this->payload(['submission_token' => $token]);

        $this->actingAs($this->user)
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('generations.index'));

        $original = Generation::query()->sole();

        // Prices have moved since the first submission.
        Saloon::fake([
            GetPricing::class => $this->pricingResponse([
                'rates' => [
                    'input_text_tokens' => '9.00',
                    'input_image_tokens' => '18.00',
                    'output_tokens' => '72.00',
                    'reasoning_tokens' => '22.50',
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('generations.index'));

        $this->assertDatabaseCount('generations', 1);

        $repeated = Generation::query()->sole();

        $this->assertSame($original->id, $repeated->id);
        $this->assertSame($original->estimated_cost_nanodollars, $repeated->estimated_cost_nanodollars);
        $this->assertSame($original->quote_snapshot, $repeated->quote_snapshot);

        // The duplicate is answered before any re-quote: only the original
        // submission fetched pricing at all.
        Saloon::assertSentCount(1);
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    }

    public function test_the_same_token_with_different_options_is_a_conflict(): void
    {
        $token = (string) Str::uuid7();
        $payload = $this->payload(['submission_token' => $token]);

        $this->actingAs($this->user)
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('generations.index'));

        $this->actingAs($this->user)
            ->postJson(route('generations.store'), $this->payload([
                'submission_token' => $token,
                'prompt' => 'A different lighthouse',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['submission_token']);

        $generation = Generation::query()->sole();

        $this->assertSame('A lighthouse', $generation->prompt);
        $this->assertSame($payload['submission_token'], $generation->submission_token);
    }

    public function test_submission_tokens_are_scoped_to_their_owner(): void
    {
        $token = (string) Str::uuid7();
        $payload = $this->payload(['submission_token' => $token]);
        $other = User::factory()->create();

        $this->actingAs($this->user)
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('generations.index'));

        $this->actingAs($other)
            ->post(route('generations.store'), $payload)
            ->assertRedirect(route('generations.index'));

        $this->assertDatabaseCount('generations', 2);
        $this->assertSame(1, Generation::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(1, Generation::query()->where('user_id', $other->id)->count());
    }

    public function test_creating_a_request_makes_no_provider_generation_call(): void
    {
        $this->actingAs($this->user)
            ->post(route('generations.store'), $this->payload())
            ->assertRedirect(route('generations.index'));

        // Only the public pricing catalogue is fetched; submission is queued work.
        Saloon::assertSentCount(1);
        Saloon::assertSent(GetPricing::class);
        Saloon::assertNotSent(CreateGeneration::class);
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    }

    public function test_a_missing_api_key_returns_a_generic_503_before_creating_anything(): void
    {
        config(['services.pixelforge.api_key' => null]);

        $response = $this->actingAs($this->user)
            ->postJson(route('generations.store'), $this->payload());

        $response->assertStatus(503)->assertJsonPath(
            'message',
            'Image generation is currently unavailable. Nothing was created or charged.',
        );
        // The generic failure never echoes the configured key or its env name.
        $this->assertStringNotContainsString('PIXELFORGE_API_KEY', $response->getContent());
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());

        $this->assertDatabaseCount('generations', 0);
        Saloon::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_blank_api_key_is_treated_as_missing(): void
    {
        config(['services.pixelforge.api_key' => '   ']);

        $this->actingAs($this->user)
            ->postJson(route('generations.store'), $this->payload())
            ->assertStatus(503);

        $this->assertDatabaseCount('generations', 0);
        Saloon::assertNothingSent();
    }

    public function test_a_pricing_failure_creates_no_request(): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make('{}', 500, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('generations.store'), $this->payload());

        $response->assertStatus(503)->assertJsonPath(
            'message',
            'Pricing is temporarily unavailable. Nothing was created or charged. Please try again shortly.',
        );

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_the_action_rejects_invalid_callers_before_quoting(): void
    {
        $action = app(RequestGeneration::class);

        try {
            $action->execute($this->user, ['prompt' => "bad\0prompt", 'size' => '1024x1024', 'quality' => 'medium'], (string) Str::uuid7());
            $this->fail('Expected invalid options to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['prompt'], array_keys($exception->errors()));
        }

        try {
            $action->execute($this->user, ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium'], 'not-a-uuid');
            $this->fail('Expected an invalid submission token to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['submission_token'], array_keys($exception->errors()));
        }

        $this->assertDatabaseCount('generations', 0);
        Saloon::assertNothingSent();
    }

    public function test_the_database_enforces_one_submission_token_per_owner(): void
    {
        $token = (string) Str::uuid7();

        Generation::factory()->create(['user_id' => $this->user->id, 'submission_token' => $token]);

        $rejected = false;

        try {
            // A savepoint keeps the surrounding test transaction usable.
            DB::transaction(function () use ($token): void {
                Generation::factory()->create(['user_id' => $this->user->id, 'submission_token' => $token]);
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertSame(1, Generation::query()->where('submission_token', $token)->count());
    }

    public function test_the_dashboard_reports_configuration_without_exposing_the_key(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('generation_enabled', true)
            );

        config(['services.pixelforge.api_key' => null]);

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('generation_enabled', false)
        );

        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'submission_token' => (string) Str::uuid7(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pricingResponse(array $overrides = []): MockResponse
    {
        $payload = array_replace_recursive($this->pricingPayload(), $overrides);

        return MockResponse::make(
            json_encode($payload, JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingPayload(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pixelforge-pricing.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
