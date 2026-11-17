<?php

namespace Tests\Feature;

use App\Actions\Pixelforge\GetPricingCatalog;
use App\Data\PixelforgePricing;
use App\Exceptions\PricingException;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class PixelforgePricingCatalogTest extends TestCase
{
    public function test_the_cache_contract_is_stable(): void
    {
        $this->assertSame('pixelforge.pricing.v1', GetPricingCatalog::CACHE_KEY);
        $this->assertSame(300, GetPricingCatalog::CACHE_TTL_SECONDS);
    }

    public function test_it_fetches_once_and_caches_a_validated_array(): void
    {
        $this->fakePricingCatalogue();

        $pricing = $this->catalogue()->get();

        $this->assertInstanceOf(PixelforgePricing::class, $pricing);
        Saloon::assertSentCount(1);

        $cached = Cache::get(GetPricingCatalog::CACHE_KEY);

        $this->assertIsArray($cached);
        $this->assertInstanceOf(PixelforgePricing::class, PixelforgePricing::fromArray($cached));

        $this->assertSame($pricing->toArray(), $this->catalogue()->get()->toArray());
        Saloon::assertSentCount(1);
    }

    public function test_it_requests_the_public_pricing_endpoint_with_explicit_timeouts(): void
    {
        $this->fakePricingCatalogue();

        $this->catalogue()->get();

        $pending = Saloon::mockClient()->getLastPendingRequest();

        $this->assertNotNull($pending);
        $this->assertSame(
            rtrim((string) config('services.pixelforge.base_url'), '/').'/pricing',
            $pending->getUrl(),
        );
        $this->assertSame(Method::GET, $pending->getMethod());
        $this->assertSame('application/json', $pending->headers()->get('Accept'));
        $this->assertNull($pending->headers()->get('Authorization'));
        $this->assertSame(3.0, $pending->config()->get('connect_timeout'));
        $this->assertSame(5.0, $pending->config()->get('timeout'));
    }

    public function test_the_connector_uses_the_configured_base_url(): void
    {
        $this->assertSame('https://pixelforge.promptedit.dev/v1', config('services.pixelforge.base_url'));
        $this->assertSame('https://pixelforge.promptedit.dev/v1', (new PixelforgeConnector)->resolveBaseUrl());

        config(['services.pixelforge.base_url' => 'https://pricing.example.test/v2/']);
        $this->assertSame('https://pricing.example.test/v2', (new PixelforgeConnector)->resolveBaseUrl());
    }

    public function test_the_catalogue_cache_expires_after_300_seconds(): void
    {
        $this->fakePricingCatalogue();

        $this->catalogue()->get();
        Saloon::assertSentCount(1);

        $this->travel(299)->seconds();
        $this->catalogue()->get();
        Saloon::assertSentCount(1);

        $this->travel(2)->seconds();
        $this->catalogue()->get();
        Saloon::assertSentCount(2);
    }

    public function test_a_valid_cached_catalogue_is_revalidated_without_calling_the_provider(): void
    {
        $cached = PixelforgePricing::fromArray($this->pricingPayload());
        Cache::put(GetPricingCatalog::CACHE_KEY, $cached->toArray(), 300);

        $this->fakePricingCatalogue();

        $pricing = $this->catalogue()->get();

        $this->assertSame($cached->toArray(), $pricing->toArray());
        Saloon::assertNothingSent();
    }

    public function test_a_malformed_cached_catalogue_is_evicted_and_refetched(): void
    {
        Cache::put(GetPricingCatalog::CACHE_KEY, ['currency' => 'usd'], 300);

        $this->fakePricingCatalogue();

        $this->assertInstanceOf(PixelforgePricing::class, $this->catalogue()->get());
        Saloon::assertSentCount(1);

        $cached = Cache::get(GetPricingCatalog::CACHE_KEY);

        $this->assertIsArray($cached);
        $this->assertNotSame(['currency' => 'usd'], $cached);
        $this->assertInstanceOf(PixelforgePricing::class, PixelforgePricing::fromArray($cached));
    }

    public function test_an_expired_catalogue_is_not_served_when_the_provider_fails(): void
    {
        $this->fakeSequence(
            $this->pricingResponse(),
            MockResponse::make('', 500),
        );

        $this->catalogue()->get();
        $this->travel(301)->seconds();

        try {
            $this->catalogue()->get();
            $this->fail('An expired catalogue must not be served.');
        } catch (PricingException) {
            // Expected: fail closed rather than serving stale prices.
        }

        $this->assertCatalogueIsNotCached();
    }

    public function test_provider_failures_are_not_cached_and_a_later_call_recovers(): void
    {
        $this->fakeSequence(
            MockResponse::make('{"error":"upstream"}', 503, ['Content-Type' => 'application/json']),
            $this->pricingResponse(),
        );

        try {
            $this->catalogue()->get();
            $this->fail('A provider failure must not produce a catalogue.');
        } catch (PricingException) {
            // Expected.
        }

        $this->assertCatalogueIsNotCached();

        $this->assertInstanceOf(PixelforgePricing::class, $this->catalogue()->get());
        $this->assertIsArray(Cache::get(GetPricingCatalog::CACHE_KEY));
        Saloon::assertSentCount(2);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorStatuses(): array
    {
        return [
            'bad request' => [400],
            'not found' => [404],
            'server error' => [500],
            'service unavailable' => [503],
        ];
    }

    #[DataProvider('errorStatuses')]
    public function test_error_responses_are_reported_as_unavailable_without_leaking_the_body(int $status): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make(
                '{"error":"upstream-secret","message":"remote detail"}',
                $status,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        try {
            $this->catalogue()->get();
            $this->fail('A non-2xx response must not produce a catalogue.');
        } catch (PricingException $exception) {
            $this->assertStringNotContainsString('upstream-secret', $exception->getMessage());
            $this->assertStringNotContainsString('remote detail', $exception->getMessage());
            $this->assertStringContainsString((string) $status, $exception->getMessage());
        }

        $this->assertCatalogueIsNotCached();
    }

    public function test_transport_failures_are_reported_as_unavailable(): void
    {
        Saloon::fake([
            GetPricing::class => function (PendingRequest $pendingRequest): never {
                throw new FatalRequestException(new RuntimeException('cURL error 28: timed out'), $pendingRequest);
            },
        ]);

        try {
            $this->catalogue()->get();
            $this->fail('A transport failure must not produce a catalogue.');
        } catch (PricingException $exception) {
            $this->assertStringNotContainsString('cURL', $exception->getMessage());
            $this->assertInstanceOf(FatalRequestException::class, $exception->getPrevious());
        }

        $this->assertCatalogueIsNotCached();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProviderPayloads(): array
    {
        $valid = static fn (): array => json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $numericRate = $valid();
        $numericRate['rates']['input_text_tokens'] = 5;

        $integerRate = $valid();
        $integerRate['rates']['output_tokens'] = 40;

        $missingRates = $valid();
        unset($missingRates['rates']);

        $wrongCurrency = $valid();
        $wrongCurrency['currency'] = 'eur';

        $wrongUnit = $valid();
        $wrongUnit['unit'] = 'per_1k_tokens';

        $missingTokenRules = $valid();
        unset($missingTokenRules['tokens']['characters_per_text_token']);

        $negativeTokens = $valid();
        $negativeTokens['tokens']['output']['low']['1024x1024'] = -5;

        return [
            'numeric decimal rate' => [json_encode($numericRate, JSON_THROW_ON_ERROR)],
            'integer rate' => [json_encode($integerRate, JSON_THROW_ON_ERROR)],
            'missing rates' => [json_encode($missingRates, JSON_THROW_ON_ERROR)],
            'unsupported currency' => [json_encode($wrongCurrency, JSON_THROW_ON_ERROR)],
            'unsupported unit' => [json_encode($wrongUnit, JSON_THROW_ON_ERROR)],
            'missing token rules' => [json_encode($missingTokenRules, JSON_THROW_ON_ERROR)],
            'negative output tokens' => [json_encode($negativeTokens, JSON_THROW_ON_ERROR)],
            'truncated json' => ['{"object":"pricing","rates":'],
            'plain text body' => ['<html>upstream error</html>'],
            'scalar json body' => ['42'],
            'empty body' => [''],
            'json list body' => ['[1,2,3]'],
        ];
    }

    #[DataProvider('invalidProviderPayloads')]
    public function test_an_invalid_provider_catalogue_is_rejected_and_not_cached(string $body): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make($body, 200, ['Content-Type' => 'application/json']),
        ]);

        try {
            $this->catalogue()->get();
            $this->fail('A malformed catalogue must not be accepted.');
        } catch (PricingException) {
            // Expected.
        }

        $this->assertCatalogueIsNotCached();
    }

    private function catalogue(): GetPricingCatalog
    {
        return $this->app->make(GetPricingCatalog::class);
    }

    private function fakePricingCatalogue(): void
    {
        Saloon::fake([
            GetPricing::class => $this->pricingResponse(),
        ]);
    }

    private function fakeSequence(MockResponse ...$responses): void
    {
        Saloon::fake($responses);
    }

    private function pricingResponse(): MockResponse
    {
        return MockResponse::make(
            (string) file_get_contents(base_path('tests/Fixtures/pixelforge-pricing.json')),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingPayload(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pixelforge-pricing.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function assertCatalogueIsNotCached(): void
    {
        $this->assertFalse(Cache::has(GetPricingCatalog::CACHE_KEY));
    }
}
