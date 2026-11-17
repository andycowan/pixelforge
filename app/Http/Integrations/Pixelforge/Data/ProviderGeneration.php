<?php

namespace App\Http\Integrations\Pixelforge\Data;

use App\Data\PixelforgePricing;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\ProviderGenerationStatus;
use App\Exceptions\PricingException;
use App\Exceptions\ProviderPayloadException;
use App\Services\GenerationPricing;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One validated provider generation object, shared by create responses,
 * retrieval responses and webhook data objects.
 *
 * The raw payload is retained because callers must be able to compare a
 * stored webhook object with the exact evidence that was verified.
 */
final readonly class ProviderGeneration
{
    private const FIELDS = [
        'id',
        'object',
        'status',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'metadata',
        'image_url',
        'image_url_expires_at',
        'usage',
        'rates',
        'error',
        'created_at',
        'started_at',
        'completed_at',
    ];

    private const ERROR_CODES = ['generation_failed', 'internal_error'];

    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     * @param  array<string, string>  $rates
     * @param  array<string, int>|null  $usage
     * @param  array{code: string, message: string}|null  $error
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $id,
        public ProviderGenerationStatus $status,
        public string $prompt,
        public GenerationSize $size,
        public GenerationQuality $quality,
        public array $referenceImages,
        public array $metadata,
        public array $rates,
        public ?array $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?array $error,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        private array $payload,
    ) {}

    /** @param array<array-key, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertEnvelope($payload);

        $id = self::text($payload['id'], 'id', 68, nonBlank: true);
        if (preg_match('/\Agen_[A-Za-z0-9]{1,64}\z/', $id) !== 1) {
            self::invalid('id');
        }

        $status = is_string($payload['status'])
            ? ProviderGenerationStatus::tryFrom($payload['status'])
            : null;
        if ($status === null) {
            self::invalid('status');
        }

        $size = is_string($payload['size']) ? GenerationSize::tryFrom($payload['size']) : null;
        if ($size === null) {
            self::invalid('size');
        }

        $quality = is_string($payload['quality'])
            ? GenerationQuality::tryFrom($payload['quality'])
            : null;
        if ($quality === null) {
            self::invalid('quality');
        }

        $rates = self::rates($payload['rates']);
        $usage = self::usage($payload['usage'], $rates);
        $imageUrl = self::url($payload['image_url'], 'image_url', nullable: true);
        $imageUrlExpiresAt = self::date($payload['image_url_expires_at'], 'image_url_expires_at', nullable: true);
        $error = self::error($payload['error']);
        $createdAt = self::date($payload['created_at'], 'created_at', nullable: false);
        if ($createdAt === null) {
            self::invalid('created_at');
        }
        $startedAt = self::date($payload['started_at'], 'started_at', nullable: true);
        $completedAt = self::date($payload['completed_at'], 'completed_at', nullable: true);

        // Compare parsed instants so ordering cannot be hidden by textual
        // differences in the accepted timestamp representation.
        if ($startedAt !== null && $startedAt < $createdAt) {
            self::invalid('started_at');
        }
        if ($completedAt !== null && $completedAt < $createdAt) {
            self::invalid('completed_at');
        }
        if ($startedAt !== null && $completedAt !== null && $completedAt < $startedAt) {
            self::invalid('completed_at');
        }

        self::assertStatusShape($status, $usage, $imageUrl, $imageUrlExpiresAt, $error, $startedAt, $completedAt);

        if ($status === ProviderGenerationStatus::Succeeded
            && $completedAt !== null
            && $imageUrlExpiresAt !== null
            && $imageUrlExpiresAt <= $completedAt) {
            self::invalid('image_url_expires_at');
        }

        return new self(
            id: $id,
            status: $status,
            prompt: self::text($payload['prompt'], 'prompt', 4000, nonBlank: true),
            size: $size,
            quality: $quality,
            referenceImages: self::referenceImages($payload['reference_images']),
            metadata: self::metadata($payload['metadata']),
            rates: $rates,
            usage: $usage,
            imageUrl: $imageUrl,
            imageUrlExpiresAt: $imageUrlExpiresAt,
            error: $error,
            createdAt: $createdAt,
            startedAt: $startedAt,
            completedAt: $completedAt,
            payload: $payload,
        );
    }

    /**
     * The original validated wire payload, for evidence comparisons.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** @param array<array-key, mixed> $payload */
    private static function assertEnvelope(array $payload): void
    {
        $keys = array_keys($payload);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);

        if ($keys !== $expected || ($payload['object'] ?? null) !== 'generation') {
            self::invalid('object');
        }
    }

    /** @return array<string, string> */
    private static function rates(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            self::invalid('rates');
        }

        try {
            return PixelforgePricing::validateRates($value);
        } catch (PricingException) {
            self::invalid('rates');
        }
    }

    /**
     * @param  array<string, string>  $rates
     * @return array<string, int>|null
     */
    private static function usage(mixed $value, array $rates): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            self::invalid('usage');
        }

        $categories = [...PixelforgePricing::TOKEN_TYPES, 'total_tokens'];
        $keys = array_keys($value);
        $sortedCategories = $categories;
        sort($keys);
        sort($sortedCategories);

        if ($keys !== $sortedCategories) {
            self::invalid('usage');
        }

        $usage = [];
        foreach ($categories as $category) {
            $count = $value[$category] ?? null;
            if (! is_int($count) || $count < 0) {
                self::invalid('usage');
            }
            $usage[$category] = $count;
        }

        try {
            // Reuses the pure calculator for the component sum, exact
            // nanodollar precision and the four accepted rate categories.
            (new GenerationPricing)->providerCost($usage, $rates);
        } catch (PricingException) {
            self::invalid('usage');
        }

        return $usage;
    }

    /** @return list<string> */
    private static function referenceImages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 4) {
            self::invalid('reference_images');
        }

        $images = [];
        foreach ($value as $image) {
            $url = self::url($image, 'reference_images', nullable: false);
            if ($url === null) {
                self::invalid('reference_images');
            }
            $images[] = $url;
        }

        return $images;
    }

    /** @return array<string, string> */
    private static function metadata(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 20) {
            self::invalid('metadata');
        }

        $metadata = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                self::invalid('metadata');
            }

            $metadata[self::text($key, 'metadata', 500, nonBlank: true)] = self::text(
                $item,
                'metadata',
                500,
                nonBlank: false,
            );
        }

        return $metadata;
    }

    /** @return array{code: string, message: string}|null */
    private static function error(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            self::invalid('error');
        }

        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['code', 'message']) {
            self::invalid('error');
        }

        $code = self::text($value['code'] ?? null, 'error', 64, nonBlank: true);
        if (! in_array($code, self::ERROR_CODES, true)) {
            self::invalid('error');
        }

        return [
            'code' => $code,
            'message' => self::text($value['message'] ?? null, 'error', 4000, nonBlank: true),
        ];
    }

    private static function url(mixed $value, string $field, bool $nullable): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }

        if (! is_string($value) || $value === '' || strlen($value) > 4096
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            self::invalid($field);
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! isset($parts['host']) || $parts['host'] === '') {
            self::invalid($field);
        }

        return $value;
    }

    private static function date(mixed $value, string $field, bool $nullable): ?CarbonImmutable
    {
        if ($value === null && $nullable) {
            return null;
        }

        if (! is_string($value)
            || preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?Z\z/', $value, $matches) !== 1) {
            self::invalid($field);
        }

        $base = $matches[1];
        $fraction = $matches[2] ?? '';
        $format = $fraction === '' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z';
        $parse = $fraction === '' ? $value : $base.'.'.str_pad($fraction, 6, '0').'Z';

        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $parse, 'UTC');
        } catch (Throwable) {
            self::invalid($field);
        }

        if (! $date instanceof CarbonImmutable
            || $date->getTimezone()->getName() !== 'UTC'
            || $date->format('Y-m-d\TH:i:s') !== $base) {
            self::invalid($field);
        }

        return $date;
    }

    private static function text(mixed $value, string $field, int $maximum, bool $nonBlank): string
    {
        if (! is_string($value)
            || ! mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0")
            || mb_strlen($value, 'UTF-8') > $maximum
            || ($nonBlank && preg_match('/\S/u', $value) !== 1)) {
            self::invalid($field);
        }

        return $value;
    }

    /**
     * @param  array<string, int>|null  $usage
     * @param  array{code: string, message: string}|null  $error
     */
    private static function assertStatusShape(
        ProviderGenerationStatus $status,
        ?array $usage,
        ?string $imageUrl,
        ?CarbonImmutable $imageUrlExpiresAt,
        ?array $error,
        ?CarbonImmutable $startedAt,
        ?CarbonImmutable $completedAt,
    ): void {
        $hasImage = $imageUrl !== null || $imageUrlExpiresAt !== null;

        if ($status === ProviderGenerationStatus::Queued) {
            if ($startedAt !== null || $completedAt !== null || $usage !== null || $hasImage || $error !== null) {
                self::invalid('status');
            }

            return;
        }

        if ($status === ProviderGenerationStatus::Processing) {
            if ($startedAt === null || $completedAt !== null || $usage !== null || $hasImage || $error !== null) {
                self::invalid('status');
            }

            return;
        }

        if ($status === ProviderGenerationStatus::Succeeded) {
            if ($startedAt === null || $completedAt === null
                || $usage === null || $imageUrl === null || $imageUrlExpiresAt === null
                || $error !== null) {
                self::invalid('status');
            }

            return;
        }

        // Failed: the provider may not have started, but it must have
        // completed without usage or an image.
        if ($completedAt === null || $usage !== null || $hasImage || $error === null) {
            self::invalid('status');
        }
    }

    private static function invalid(string $field): never
    {
        throw new ProviderPayloadException("Invalid Pixelforge generation [{$field}].");
    }
}
