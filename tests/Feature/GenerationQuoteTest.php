<?php

namespace Tests\Feature;

use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class GenerationQuoteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Log::spy();

        Saloon::fake([
            GetPricing::class => $this->pricingResponse(),
        ]);

        $this->user = User::factory()->create();
    }

    public function test_guests_receive_a_json_401_and_no_provider_call(): void
    {
        $this->postJson(route('generations.quote'), $this->validPayload())
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);

        Saloon::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_unverified_users_are_forbidden(): void
    {
        $unverified = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($unverified)
            ->postJson(route('generations.quote'), $this->validPayload())
            ->assertForbidden();

        Saloon::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_it_returns_the_authoritative_flat_quote_projection(): void
    {
        $response = $this->quote()->assertOk();

        $response->assertExactJson([
            // Customer prices only: no provider cost, tokens, rates or markup.
            'currency' => 'usd',
            'estimated_cost_usd' => '0.080000000',
            'maximum_cost_usd' => '0.090000000',
            // A new user has no credit; CreditBalanceTest covers the true case.
            'affordable' => false,
        ]);

        $this->assertQuoteIsNotCacheable($response);
        Saloon::assertSentCount(1);
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_reference_images_are_counted_individually(): void
    {
        $reference = 'https://example.com/reference.png';

        $response = $this->quote(['reference_images' => [$reference, $reference]])->assertOk();

        // Two copies of the same reference raise the price over the no-reference $0.08/$0.09.
        $response->assertJsonPath('estimated_cost_usd', '0.100000000');
        $response->assertJsonPath('maximum_cost_usd', '0.110000000');
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_ordered_references_and_an_empty_reference_list_are_accepted(): void
    {
        $references = [
            'https://example.com/first.png',
            'http://example.com/second.jpg',
            'https://example.com/first.png',
        ];

        $this->quote(['reference_images' => $references])->assertOk();

        $this->quote(['reference_images' => []])->assertOk();

        Saloon::assertSentCount(1);
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_a_prompt_at_the_unicode_limit_is_accepted(): void
    {
        $this->quote(['prompt' => str_repeat("\u{1F5BC}", 4000)])->assertOk();

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_client_supplied_money_and_quote_snapshots_are_ignored(): void
    {
        $response = $this->quote([
            'estimated_cost_usd' => '0.000000001',
            'maximum_cost_usd' => '0.000000002',
            'estimated_cost_nanodollars' => 1,
            'quote_snapshot' => ['rates' => [], 'policy' => []],
        ])->assertOk();

        $response->assertJsonPath('estimated_cost_usd', '0.080000000');
        $response->assertJsonPath('maximum_cost_usd', '0.090000000');
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_required_generation_options_must_be_present(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'size', 'quality']);

        Saloon::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidInputs(): array
    {
        return [
            'blank prompt' => [
                ['prompt' => '   '],
                ['prompt'],
            ],
            'unicode blank prompt' => [
                ['prompt' => "\u{00A0}\u{2003}\u{2028}"],
                ['prompt'],
            ],
            'prompt over the character limit' => [
                ['prompt' => str_repeat('a', 4001)],
                ['prompt'],
            ],
            'unicode prompt over the character limit' => [
                ['prompt' => str_repeat("\u{1F5BC}", 4001)],
                ['prompt'],
            ],
            'prompt is not a string' => [
                ['prompt' => ['A lighthouse']],
                ['prompt'],
            ],
            'unsupported size' => [
                ['size' => '2048x2048'],
                ['size'],
            ],
            'size is not a string' => [
                ['size' => 1024],
                ['size'],
            ],
            'unsupported quality' => [
                ['quality' => 'ultra'],
                ['quality'],
            ],
            'reference images is not an array' => [
                ['reference_images' => 'https://example.com/reference.png'],
                ['reference_images'],
            ],
            'reference images is an object' => [
                ['reference_images' => ['first' => 'https://example.com/reference.png']],
                ['reference_images'],
            ],
            'more than four reference images' => [
                ['reference_images' => array_fill(0, 5, 'https://example.com/reference.png')],
                ['reference_images'],
            ],
            'reference image is not a string' => [
                ['reference_images' => [123]],
                ['reference_images.0'],
            ],
            'reference image is not a URL' => [
                ['reference_images' => ['not a url']],
                ['reference_images.0'],
            ],
            'reference image uses an unsupported scheme' => [
                ['reference_images' => ['ftp://example.com/reference.png']],
                ['reference_images.0'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $errors
     */
    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_inputs(array $overrides, array $errors): void
    {
        $this->actingAs($this->user)
            ->postJson(route('generations.quote'), array_replace($this->validPayload(), $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        Saloon::assertNothingSent();
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_provider_failures_return_a_generic_503_without_leaking_the_response(): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make(
                '{"error":"internal-upstream-secret","detail":"provider stack trace"}',
                500,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->quote()
            ->assertStatus(503)
            ->assertJson(['message' => 'Pricing is temporarily unavailable. Please try again shortly.']);

        $this->assertStringNotContainsString('internal-upstream-secret', $response->getContent());
        $this->assertStringNotContainsString('provider stack trace', $response->getContent());
        $this->assertQuoteIsNotCacheable($response);
        $this->assertDatabaseCount('generations', 0);

        // The cause is reported server-side, but the response above stays generic.
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_a_malformed_provider_catalogue_returns_a_generic_503(): void
    {
        $catalogue = $this->pricingPayload();
        $catalogue['rates']['input_text_tokens'] = 5;

        Saloon::fake([
            GetPricing::class => MockResponse::make(
                json_encode($catalogue, JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->quote()
            ->assertStatus(503)
            ->assertJson(['message' => 'Pricing is temporarily unavailable. Please try again shortly.']);

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_the_quote_route_is_authenticated_verified_and_throttled(): void
    {
        $route = Route::getRoutes()->getByName('generations.quote');

        $this->assertNotNull($route);
        $this->assertSame('generations/quote', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(url('/generations/quote'), route('generations.quote'));

        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains('throttle:120,1,generation-quotes:', $middleware);
    }

    public function test_the_quote_endpoint_is_post_only(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('generations.quote'))
            ->assertStatus(405);
    }

    public function test_quote_requests_are_throttled_at_120_per_minute(): void
    {
        for ($request = 1; $request <= 120; $request++) {
            $this->quote()->assertOk();
        }

        $this->quote()->assertStatus(429);

        // The catalogue is cached, so the browser-facing limit never multiplies
        // outbound provider traffic.
        Saloon::assertSentCount(1);
        $this->assertDatabaseCount('generations', 0);
    }

    private function quote(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson(route('generations.quote'), array_replace($this->validPayload(), $overrides));
    }

    /**
     * @return array{prompt: string, size: string, quality: string}
     */
    private function validPayload(): array
    {
        return [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ];
    }

    private function pricingResponse(): MockResponse
    {
        return MockResponse::make(
            $this->pricingJson(),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    private function pricingJson(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/pixelforge-pricing.json'));
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingPayload(): array
    {
        return json_decode($this->pricingJson(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertQuoteIsNotCacheable(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }
}
