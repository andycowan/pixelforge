<?php

namespace Tests\Feature;

use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\CreateGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetGeneration;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

final class PixelforgeGenerationRequestsTest extends TestCase
{
    private const IDEMPOTENCY_KEY = '01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    private const PROVIDER_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    private const TEST_KEY = 'unit-test-key-not-a-real-credential';

    public function test_configuration_exposes_provider_credentials_without_defaults(): void
    {
        $this->assertArrayHasKey('api_key', config('services.pixelforge'));
        $this->assertArrayHasKey('webhook_secret', config('services.pixelforge'));
    }

    public function test_a_connector_without_a_key_stays_anonymous_for_pricing(): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        (new PixelforgeConnector)->send(new GetPricing);

        $pending = Saloon::mockClient()->getLastPendingRequest();

        $this->assertNotNull($pending);
        $this->assertNull($pending->headers()->get('Authorization'));
        $this->assertSame(3.0, $pending->config()->get('connect_timeout'));
        $this->assertSame(5.0, $pending->config()->get('timeout'));
    }

    public function test_the_container_resolves_the_anonymous_connector(): void
    {
        $connector = $this->app->make(PixelforgeConnector::class);
        $this->assertInstanceOf(PixelforgeConnector::class, $connector);

        Saloon::fake([
            GetPricing::class => MockResponse::make('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        $connector->send(new GetPricing);

        $this->assertNull(Saloon::mockClient()->getLastPendingRequest()?->headers()->get('Authorization'));
    }

    public function test_an_explicit_key_adds_bearer_auth(): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        (new PixelforgeConnector(self::TEST_KEY))->send(new GetPricing);

        $pending = Saloon::mockClient()->getLastPendingRequest();
        $this->assertSame('Bearer '.self::TEST_KEY, $pending?->headers()->get('Authorization'));
        $this->assertSame(3.0, $pending?->config()->get('connect_timeout'));
        $this->assertSame(5.0, $pending?->config()->get('timeout'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankKeys(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('blankKeys')]
    public function test_a_blank_explicit_key_remains_anonymous(string $key): void
    {
        Saloon::fake([
            GetPricing::class => MockResponse::make('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        (new PixelforgeConnector($key))->send(new GetPricing);

        $this->assertNull(Saloon::mockClient()->getLastPendingRequest()?->headers()->get('Authorization'));
    }

    public function test_create_sends_the_contract_body_with_the_stable_idempotency_key(): void
    {
        Saloon::fake([
            CreateGeneration::class => $this->providerResponse('queued', 202),
        ]);

        $request = new CreateGeneration(self::IDEMPOTENCY_KEY, $this->createPayload());
        $response = (new PixelforgeConnector(self::TEST_KEY))->send($request);
        $generation = $response->dtoOrFail();

        $this->assertInstanceOf(ProviderGeneration::class, $generation);
        $this->assertSame(self::PROVIDER_ID, $generation->id);
        $this->assertSame('queued', $generation->status->value);

        $pending = Saloon::mockClient()->getLastPendingRequest();
        $this->assertNotNull($pending);
        $this->assertSame(
            rtrim((string) config('services.pixelforge.base_url'), '/').'/generations',
            $pending->getUrl(),
        );
        $this->assertSame(Method::POST, $pending->getMethod());
        $this->assertSame(self::IDEMPOTENCY_KEY, $pending->headers()->get('Idempotency-Key'));
        $this->assertSame('application/json', $pending->headers()->get('Content-Type'));
        $this->assertSame('application/json', $pending->headers()->get('Accept'));
        $this->assertSame('Bearer '.self::TEST_KEY, $pending->headers()->get('Authorization'));
        $this->assertSame(3.0, $pending->config()->get('connect_timeout'));
        $this->assertSame(5.0, $pending->config()->get('timeout'));
        $this->assertNull($request->tries);
        $this->assertNull($request->retryInterval);
        $this->assertSame($this->createPayload(), $pending->body()?->all());
    }

    public function test_get_retrieves_a_succeeded_generation_through_the_shared_dto(): void
    {
        Saloon::fake([
            GetGeneration::class => $this->providerResponse('succeeded'),
        ]);

        $request = new GetGeneration(self::PROVIDER_ID);
        $generation = (new PixelforgeConnector(self::TEST_KEY))->send($request)->dtoOrFail();

        $this->assertInstanceOf(ProviderGeneration::class, $generation);
        $this->assertSame('succeeded', $generation->status->value);
        $this->assertSame(10411, $generation->usage['total_tokens'] ?? null);
        $this->assertSame($this->fixturePayload('succeeded'), $generation->toArray());

        $pending = Saloon::mockClient()->getLastPendingRequest();
        $this->assertSame(
            rtrim((string) config('services.pixelforge.base_url'), '/').'/generations/'.self::PROVIDER_ID,
            $pending?->getUrl(),
        );
        $this->assertSame(Method::GET, $pending?->getMethod());
        $this->assertSame('Bearer '.self::TEST_KEY, $pending?->headers()->get('Authorization'));
        $this->assertNull($request->tries);
        $this->assertNull($request->retryInterval);
    }

    public function test_create_and_get_return_the_same_dto_for_the_same_wire_object(): void
    {
        Saloon::fake([
            CreateGeneration::class => $this->providerResponse('queued', 202),
            GetGeneration::class => $this->providerResponse('queued'),
        ]);

        $connector = new PixelforgeConnector(self::TEST_KEY);
        $created = $connector->send(new CreateGeneration(self::IDEMPOTENCY_KEY, $this->createPayload()))->dtoOrFail();
        $retrieved = $connector->send(new GetGeneration(self::PROVIDER_ID))->dtoOrFail();

        $this->assertInstanceOf(ProviderGeneration::class, $created);
        $this->assertInstanceOf(ProviderGeneration::class, $retrieved);
        $this->assertSame($created->toArray(), $retrieved->toArray());
        $this->assertSame($created->id, $retrieved->id);
        $this->assertSame($created->status, $retrieved->status);
        $this->assertSame($created->createdAt->toDateTimeString(), $retrieved->createdAt->toDateTimeString());
    }

    public function test_correlation_metadata_is_exact_and_never_contains_the_raw_key(): void
    {
        Saloon::fake([
            CreateGeneration::class => $this->providerResponse('queued', 202),
        ]);

        $payload = $this->createPayload();
        (new PixelforgeConnector(self::TEST_KEY))->send(new CreateGeneration(self::IDEMPOTENCY_KEY, $payload));

        $body = Saloon::mockClient()->getLastPendingRequest()?->body()?->all();
        $this->assertIsArray($body);
        $this->assertSame([
            'generation_id' => self::IDEMPOTENCY_KEY,
            'key_fingerprint' => hash('sha256', self::TEST_KEY),
        ], $body['metadata']);
        $this->assertStringNotContainsString(self::TEST_KEY, (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSuccessBodies(): array
    {
        return [
            'missing generation fields' => ['{"id":"gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K"}'],
            'truncated json' => ['{"id":'],
            'empty body' => [''],
            'scalar json' => ['42'],
            'json list' => ['[]'],
            'plain text' => ['<html>upstream</html>'],
        ];
    }

    #[DataProvider('malformedSuccessBodies')]
    public function test_a_malformed_success_response_raises_the_provider_payload_exception(string $body): void
    {
        Saloon::fake([
            CreateGeneration::class => MockResponse::make($body, 202, ['Content-Type' => 'application/json']),
        ]);

        try {
            (new PixelforgeConnector(self::TEST_KEY))
                ->send(new CreateGeneration(self::IDEMPOTENCY_KEY, $this->createPayload()))
                ->dtoOrFail();
            $this->fail('A malformed generation object must not become a DTO.');
        } catch (ProviderPayloadException $exception) {
            $this->assertStringContainsString('Invalid Pixelforge generation', $exception->getMessage());
        }
    }

    public function test_provider_http_failures_are_not_retried_by_the_request(): void
    {
        Saloon::fake([
            CreateGeneration::class => MockResponse::make(
                '{"error":{"code":"validation_failed","message":"remote detail"}}',
                422,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $request = new CreateGeneration(self::IDEMPOTENCY_KEY, $this->createPayload());
        $response = (new PixelforgeConnector(self::TEST_KEY))->send($request);

        $this->assertTrue($response->failed());
        $this->assertSame(422, $response->status());
        Saloon::assertSentCount(1);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidCreatePayloads(): array
    {
        $invalid = [];
        $base = self::baseCreatePayload();

        $missingMetadata = $base;
        unset($missingMetadata['metadata']);
        $invalid['missing metadata'] = [$missingMetadata];

        $extraMetadata = $base;
        $extraMetadata['metadata']['user_id'] = '1';
        $invalid['extra metadata identity'] = [$extraMetadata];

        $unknownField = $base;
        $unknownField['user_id'] = 1;
        $invalid['unknown body field'] = [$unknownField];

        $missingSize = $base;
        unset($missingSize['size']);
        $invalid['missing size'] = [$missingSize];

        $badSize = $base;
        $badSize['size'] = '2048x2048';
        $invalid['invalid size'] = [$badSize];

        $badQuality = $base;
        $badQuality['quality'] = 'ultra';
        $invalid['invalid quality'] = [$badQuality];

        $blankPrompt = $base;
        $blankPrompt['prompt'] = '  ';
        $invalid['blank prompt'] = [$blankPrompt];

        $longPrompt = $base;
        $longPrompt['prompt'] = str_repeat('a', 4001);
        $invalid['overlong prompt'] = [$longPrompt];

        $manyImages = $base;
        $manyImages['reference_images'] = array_fill(0, 5, 'https://example.com/style.png');
        $invalid['too many reference images'] = [$manyImages];

        $badImage = $base;
        $badImage['reference_images'] = ['not-a-url'];
        $invalid['invalid reference image'] = [$badImage];

        $rawKeyFingerprint = $base;
        $rawKeyFingerprint['metadata']['key_fingerprint'] = self::TEST_KEY;
        $invalid['raw key as fingerprint'] = [$rawKeyFingerprint];

        $blankGenerationId = $base;
        $blankGenerationId['metadata']['generation_id'] = '';
        $invalid['blank generation id'] = [$blankGenerationId];

        return $invalid;
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidCreatePayloads')]
    public function test_create_rejects_unsafe_or_incomplete_contract_payloads(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CreateGeneration(self::IDEMPOTENCY_KEY, $payload);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdempotencyKeys(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'too long' => [str_repeat('a', 256)],
            'header injection' => ["key\r\nX-Injected: 1"],
        ];
    }

    #[DataProvider('invalidIdempotencyKeys')]
    public function test_create_rejects_an_unsafe_idempotency_key(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CreateGeneration($key, self::baseCreatePayload());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProviderIds(): array
    {
        return [
            'empty' => [''],
            'missing prefix' => ['01JC2Z8Q4N6V0M3H7X5T9R2B1K'],
            'path traversal' => ['gen_../secrets'],
            'another resource' => ['evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'],
            'query injection' => ['gen_abc?status=failed'],
        ];
    }

    #[DataProvider('invalidProviderIds')]
    public function test_get_rejects_an_unsafe_provider_id(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GetGeneration($id);
    }

    private function providerResponse(string $fixture, int $status = 200): MockResponse
    {
        return MockResponse::make(
            (string) file_get_contents(base_path("tests/Fixtures/provider/{$fixture}.json")),
            $status,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixturePayload(string $fixture): array
    {
        return json_decode(
            (string) file_get_contents(base_path("tests/Fixtures/provider/{$fixture}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(): array
    {
        $payload = self::baseCreatePayload();
        $payload['metadata']['key_fingerprint'] = hash('sha256', self::TEST_KEY);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseCreatePayload(): array
    {
        return [
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'metadata' => [
                'generation_id' => self::IDEMPOTENCY_KEY,
                'key_fingerprint' => str_repeat('a', 64),
            ],
        ];
    }
}
