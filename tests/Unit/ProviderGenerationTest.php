<?php

namespace Tests\Unit;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\ProviderGenerationStatus;
use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Services\GenerationPricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderGenerationTest extends TestCase
{
    public function test_it_parses_a_queued_generation(): void
    {
        $payload = self::fixture('queued');
        $generation = ProviderGeneration::fromArray($payload);

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->id);
        $this->assertSame(ProviderGenerationStatus::Queued, $generation->status);
        $this->assertSame('A lighthouse on a cliff at dawn, oil painting', $generation->prompt);
        $this->assertSame(GenerationSize::Landscape, $generation->size);
        $this->assertSame(GenerationQuality::High, $generation->quality);
        $this->assertSame(['https://example.com/style.png'], $generation->referenceImages);
        $this->assertSame([
            'generation_id' => '01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'key_fingerprint' => str_repeat('a', 64),
        ], $generation->metadata);
        $this->assertSame([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ], $generation->rates);
        $this->assertNull($generation->usage);
        $this->assertNull($generation->imageUrl);
        $this->assertNull($generation->imageUrlExpiresAt);
        $this->assertNull($generation->error);
        $this->assertSame('2026-11-04T14:03:11Z', $generation->createdAt->toIso8601ZuluString());
        $this->assertNull($generation->startedAt);
        $this->assertNull($generation->completedAt);
    }

    public function test_it_parses_processing_success_and_failure_objects(): void
    {
        $processing = ProviderGeneration::fromArray(self::fixture('processing'));
        $this->assertSame(ProviderGenerationStatus::Processing, $processing->status);
        $this->assertSame('2026-11-04T14:03:13Z', $processing->startedAt?->toIso8601ZuluString());
        $this->assertNull($processing->completedAt);

        $succeeded = ProviderGeneration::fromArray(self::fixture('succeeded'));
        $this->assertSame(ProviderGenerationStatus::Succeeded, $succeeded->status);
        $this->assertSame([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ], $succeeded->usage);
        $this->assertStringStartsWith('https://', (string) $succeeded->imageUrl);
        $this->assertSame('2026-11-05T14:03:29Z', $succeeded->imageUrlExpiresAt?->toIso8601ZuluString());
        $this->assertSame('2026-11-04T14:03:29Z', $succeeded->completedAt?->toIso8601ZuluString());
        $this->assertNull($succeeded->error);

        $failed = ProviderGeneration::fromArray(self::fixture('failed'));
        $this->assertSame(ProviderGenerationStatus::Failed, $failed->status);
        $this->assertNull($failed->usage);
        $this->assertNull($failed->imageUrl);
        $this->assertSame([
            'code' => 'internal_error',
            'message' => 'Something went wrong on our side while generating. You were not charged; please retry.',
        ], $failed->error);
        $this->assertSame('2026-11-04T14:04:02Z', $failed->completedAt?->toIso8601ZuluString());
    }

    public function test_the_original_wire_payload_is_preserved_for_evidence(): void
    {
        foreach (['queued', 'processing', 'succeeded', 'failed'] as $name) {
            $payload = self::fixture($name);

            $this->assertSame($payload, ProviderGeneration::fromArray($payload)->toArray());
        }
    }

    public function test_success_usage_and_rates_calculate_an_exact_provider_cost(): void
    {
        $succeeded = ProviderGeneration::fromArray(self::fixture('succeeded'));

        $this->assertNotNull($succeeded->usage);
        $this->assertSame(305_510_000, (new GenerationPricing)->providerCost($succeeded->usage, $succeeded->rates));
    }

    public function test_iso_utc_timestamps_with_fractional_seconds_are_preserved(): void
    {
        $payload = self::fixture('queued');
        $payload['created_at'] = '2026-11-04T14:03:11.123Z';

        $generation = ProviderGeneration::fromArray($payload);

        $this->assertSame('2026-11-04T14:03:11.123000Z', $generation->createdAt->toIso8601ZuluString('microsecond'));
        $this->assertSame('2026-11-04T14:03:11.123Z', $generation->toArray()['created_at']);
    }

    public function test_a_failure_before_processing_may_have_no_started_timestamp(): void
    {
        $payload = self::fixture('failed');
        $payload['started_at'] = null;

        $failed = ProviderGeneration::fromArray($payload);

        $this->assertNull($failed->startedAt);
        $this->assertNotNull($failed->completedAt);
    }

    public function test_generation_failed_is_a_supported_terminal_error_code(): void
    {
        $payload = self::fixture('failed');
        $payload['error']['code'] = 'generation_failed';

        $this->assertSame('generation_failed', ProviderGeneration::fromArray($payload)->error['code']);
    }

    public function test_reference_image_order_and_duplicates_are_preserved(): void
    {
        $payload = self::fixture('queued');
        $payload['reference_images'] = [
            'https://example.com/two.png',
            'https://example.com/one.png',
            'https://example.com/two.png',
            'https://example.com/three.png',
        ];

        $this->assertSame($payload['reference_images'], ProviderGeneration::fromArray($payload)->referenceImages);
    }

    public function test_an_empty_metadata_object_is_accepted(): void
    {
        $payload = self::fixture('queued');
        $payload['metadata'] = [];

        $this->assertSame([], ProviderGeneration::fromArray($payload)->metadata);
    }

    public function test_invalid_payload_messages_never_echo_the_payload(): void
    {
        $payload = self::fixture('queued');
        $payload['metadata']['secret'] = str_repeat('private-value', 100);

        try {
            ProviderGeneration::fromArray($payload);
            $this->fail('Invalid metadata must be rejected.');
        } catch (ProviderPayloadException $exception) {
            $this->assertStringNotContainsString('private-value', $exception->getMessage());
            $this->assertStringContainsString('[metadata]', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        $invalid = [];
        $queued = self::fixture('queued');
        $succeeded = self::fixture('succeeded');
        $failed = self::fixture('failed');
        $url = 'https://example.com/style.png';

        $wrongObject = $queued;
        $wrongObject['object'] = 'event';
        $invalid['wrong envelope object'] = [$wrongObject];

        $missingRates = $queued;
        unset($missingRates['rates']);
        $invalid['missing rates'] = [$missingRates];

        $extraPrice = $queued;
        $extraPrice['price'] = '0.30';
        $invalid['arbitrary price field'] = [$extraPrice];

        $unknownStatus = $queued;
        $unknownStatus['status'] = 'cancelled';
        $invalid['unknown status'] = [$unknownStatus];

        $invalidSize = $queued;
        $invalidSize['size'] = '2048x2048';
        $invalid['unsupported size'] = [$invalidSize];

        $invalidQuality = $queued;
        $invalidQuality['quality'] = 'ultra';
        $invalid['unsupported quality'] = [$invalidQuality];

        $blankPrompt = $queued;
        $blankPrompt['prompt'] = " \n\t ";
        $invalid['blank prompt'] = [$blankPrompt];

        $longPrompt = $queued;
        $longPrompt['prompt'] = str_repeat('a', 4001);
        $invalid['overlong prompt'] = [$longPrompt];

        $emojiPrompt = $queued;
        $emojiPrompt['prompt'] = str_repeat('😀', 4001);
        $invalid['overlong codepoint prompt'] = [$emojiPrompt];

        $nulPrompt = $queued;
        $nulPrompt['prompt'] = "A\0B";
        $invalid['nul prompt'] = [$nulPrompt];

        $invalidUtf8 = $queued;
        $invalidUtf8['prompt'] = "\xFF";
        $invalid['invalid utf-8 prompt'] = [$invalidUtf8];

        $fiveImages = $queued;
        $fiveImages['reference_images'] = array_fill(0, 5, $url);
        $invalid['too many reference images'] = [$fiveImages];

        $notAUrl = $queued;
        $notAUrl['reference_images'] = ['not-a-url'];
        $invalid['reference not a url'] = [$notAUrl];

        $ftpUrl = $queued;
        $ftpUrl['reference_images'] = ['ftp://example.com/style.png'];
        $invalid['non-http reference'] = [$ftpUrl];

        $spacedUrl = $queued;
        $spacedUrl['reference_images'] = ['https://example.com/a style.png'];
        $invalid['reference with spaces'] = [$spacedUrl];

        $listMetadata = $queued;
        $listMetadata['metadata'] = ['a', 'b'];
        $invalid['list metadata'] = [$listMetadata];

        $manyMetadata = $queued;
        $manyMetadata['metadata'] = array_combine(
            array_map(static fn (int $i): string => "key_{$i}", range(1, 21)),
            array_fill(0, 21, 'value'),
        );
        $invalid['too many metadata keys'] = [$manyMetadata];

        $longMetadata = $queued;
        $longMetadata['metadata']['generation_id'] = str_repeat('a', 501);
        $invalid['overlong metadata value'] = [$longMetadata];

        $blankMetadataKey = $queued;
        $blankMetadataKey['metadata'] = ['generation_id' => 'x', 'key_fingerprint' => 'y', '' => 'z'];
        $invalid['blank metadata key'] = [$blankMetadataKey];

        $numericMetadataKey = $queued;
        $numericMetadataKey['metadata'][0] = 'value';
        $invalid['numeric metadata key'] = [$numericMetadataKey];

        $floatRate = $queued;
        $floatRate['rates']['input_text_tokens'] = 5.0;
        $invalid['float rate'] = [$floatRate];

        $integerRate = $queued;
        $integerRate['rates']['input_text_tokens'] = 5;
        $invalid['integer rate'] = [$integerRate];

        $negativeRate = $queued;
        $negativeRate['rates']['input_text_tokens'] = '-5.00';
        $invalid['negative rate'] = [$negativeRate];

        $missingRate = $queued;
        unset($missingRate['rates']['reasoning_tokens']);
        $invalid['missing rate category'] = [$missingRate];

        $precisionRate = $succeeded;
        $precisionRate['rates'] = array_fill_keys([
            'input_text_tokens',
            'input_image_tokens',
            'output_tokens',
            'reasoning_tokens',
        ], '0.0000001');
        $invalid['non-nanodollar provider cost'] = [$precisionRate];

        $floatUsage = $succeeded;
        $floatUsage['usage']['input_text_tokens'] = 12.0;
        $invalid['float usage'] = [$floatUsage];

        $missingTotal = $succeeded;
        unset($missingTotal['usage']['total_tokens']);
        $invalid['usage without total'] = [$missingTotal];

        $inconsistentTotal = $succeeded;
        $inconsistentTotal['usage']['total_tokens'] = 10412;
        $invalid['inconsistent usage total'] = [$inconsistentTotal];

        $extraUsage = $succeeded;
        $extraUsage['usage']['cached_tokens'] = 0;
        $invalid['unknown usage category'] = [$extraUsage];

        $negativeUsage = $succeeded;
        $negativeUsage['usage']['input_text_tokens'] = -1;
        $invalid['negative usage'] = [$negativeUsage];

        $missingSuccessUsage = $succeeded;
        $missingSuccessUsage['usage'] = null;
        $invalid['success without usage'] = [$missingSuccessUsage];

        $missingSuccessImage = $succeeded;
        $missingSuccessImage['image_url'] = null;
        $invalid['success without image'] = [$missingSuccessImage];

        $missingExpiry = $succeeded;
        $missingExpiry['image_url_expires_at'] = null;
        $invalid['success without image expiry'] = [$missingExpiry];

        $expiredBeforeCompletion = $succeeded;
        $expiredBeforeCompletion['image_url_expires_at'] = '2026-11-04T14:03:28Z';
        $invalid['image expiry before completion'] = [$expiredBeforeCompletion];

        $successError = $succeeded;
        $successError['error'] = ['code' => 'internal_error', 'message' => 'unexpected'];
        $invalid['success with error'] = [$successError];

        $queuedUsage = $queued;
        $queuedUsage['usage'] = $succeeded['usage'];
        $invalid['queued with usage'] = [$queuedUsage];

        $queuedImage = $queued;
        $queuedImage['image_url'] = $succeeded['image_url'];
        $invalid['queued with image'] = [$queuedImage];

        $queuedError = $queued;
        $queuedError['error'] = $failed['error'];
        $invalid['queued with error'] = [$queuedError];

        $queuedCompleted = $queued;
        $queuedCompleted['completed_at'] = '2026-11-04T14:03:29Z';
        $invalid['queued with completion'] = [$queuedCompleted];

        $queuedStarted = $queued;
        $queuedStarted['started_at'] = '2026-11-04T14:03:13Z';
        $invalid['queued with start'] = [$queuedStarted];

        $processingWithoutStart = self::fixture('processing');
        $processingWithoutStart['started_at'] = null;
        $invalid['processing without start'] = [$processingWithoutStart];

        $processingCompleted = self::fixture('processing');
        $processingCompleted['completed_at'] = '2026-11-04T14:03:29Z';
        $invalid['processing with completion'] = [$processingCompleted];

        $failedWithoutError = $failed;
        $failedWithoutError['error'] = null;
        $invalid['failure without error'] = [$failedWithoutError];

        $failedUsage = $failed;
        $failedUsage['usage'] = $succeeded['usage'];
        $invalid['failure with usage'] = [$failedUsage];

        $failedImage = $failed;
        $failedImage['image_url'] = $succeeded['image_url'];
        $failedImage['image_url_expires_at'] = $succeeded['image_url_expires_at'];
        $invalid['failure with image'] = [$failedImage];

        $unknownError = $failed;
        $unknownError['error']['code'] = 'model_failed';
        $invalid['unsupported error code'] = [$unknownError];

        $extraError = $failed;
        $extraError['error']['param'] = 'prompt';
        $invalid['extra error field'] = [$extraError];

        $failedWithoutCompletion = $failed;
        $failedWithoutCompletion['completed_at'] = null;
        $invalid['failure without completion'] = [$failedWithoutCompletion];

        $invalidCreated = $queued;
        $invalidCreated['created_at'] = '2026-11-04 14:03:11';
        $invalid['non-iso created timestamp'] = [$invalidCreated];

        $offsetDate = $queued;
        $offsetDate['created_at'] = '2026-11-04T14:03:11+00:00';
        $invalid['non-utc timestamp'] = [$offsetDate];

        $startedBeforeCreated = $queued;
        $startedBeforeCreated['started_at'] = '2026-11-04T14:03:10Z';
        $invalid['started before created'] = [$startedBeforeCreated];

        $completedBeforeStarted = $succeeded;
        $completedBeforeStarted['completed_at'] = '2026-11-04T14:03:12Z';
        $invalid['completed before started'] = [$completedBeforeStarted];

        $completedBeforeCreated = $failed;
        $completedBeforeCreated['started_at'] = null;
        $completedBeforeCreated['completed_at'] = '2026-11-04T14:03:10Z';
        $invalid['completed before created'] = [$completedBeforeCreated];

        $ftpImage = $succeeded;
        $ftpImage['image_url'] = 'ftp://example.com/image.png';
        $invalid['non-http image'] = [$ftpImage];

        $javascriptImage = $succeeded;
        $javascriptImage['image_url'] = 'javascript:alert(1)';
        $invalid['javascript image'] = [$javascriptImage];

        $badId = $queued;
        $badId['id'] = '01JC2Z8Q4N6V0M3H7X5T9R2B1K';
        $invalid['unprefixed id'] = [$badId];

        return $invalid;
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_generation_object_is_rejected(array $payload): void
    {
        $this->expectException(ProviderPayloadException::class);
        $this->expectExceptionMessage('Invalid Pixelforge generation');

        ProviderGeneration::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__."/../Fixtures/provider/{$name}.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
