<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use InvalidArgumentException;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use Throwable;

/**
 * Submit one immutable generation request.
 *
 * Retry timing belongs to the submission job: this request has no Saloon
 * retries and carries the caller's stable idempotency key unchanged.
 */
final class CreateGeneration extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @var array<string, mixed> */
    private readonly array $payload;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(private readonly string $idempotencyKey, array $payload)
    {
        if (trim($idempotencyKey) === ''
            || strlen($idempotencyKey) > 255
            || preg_match('/[\x00-\x20\x7F]/', $idempotencyKey) === 1) {
            throw new InvalidArgumentException('A Pixelforge idempotency key must be 1-255 printable characters.');
        }

        $known = ['prompt', 'size', 'quality', 'reference_images', 'metadata'];
        $keys = array_keys($payload);
        if (array_diff($keys, $known) !== [] || array_diff($known, $keys) !== []) {
            throw new InvalidArgumentException('A Pixelforge generation payload must contain exactly the supported fields.');
        }

        $this->payload = [
            'prompt' => self::prompt($payload['prompt']),
            'size' => self::size($payload['size']),
            'quality' => self::quality($payload['quality']),
            'reference_images' => self::referenceImages($payload['reference_images']),
            'metadata' => self::metadata($payload['metadata']),
        ];
    }

    public function resolveEndpoint(): string
    {
        return 'generations';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function createDtoFromResponse(Response $response): ProviderGeneration
    {
        try {
            /** @var mixed $payload */
            $payload = $response->json();
        } catch (Throwable $exception) {
            throw new ProviderPayloadException('Invalid Pixelforge generation response.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ProviderPayloadException('Invalid Pixelforge generation response.');
        }

        return ProviderGeneration::fromArray($payload);
    }

    private static function prompt(mixed $value): string
    {
        if (! is_string($value)
            || ! mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0")
            || mb_strlen($value, 'UTF-8') > 4000
            || preg_match('/\S/u', $value) !== 1) {
            throw new InvalidArgumentException('A Pixelforge prompt must be nonblank and at most 4000 characters.');
        }

        return $value;
    }

    private static function size(mixed $value): string
    {
        if (! is_string($value) || GenerationSize::tryFrom($value) === null) {
            throw new InvalidArgumentException('Unsupported Pixelforge generation size.');
        }

        return $value;
    }

    private static function quality(mixed $value): string
    {
        if (! is_string($value) || GenerationQuality::tryFrom($value) === null) {
            throw new InvalidArgumentException('Unsupported Pixelforge generation quality.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function referenceImages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 4) {
            throw new InvalidArgumentException('Pixelforge accepts at most four reference images.');
        }

        $images = [];
        foreach ($value as $image) {
            if (! is_string($image) || $image === ''
                || strlen($image) > 4096
                || preg_match('/[\x00-\x20\x7F]/', $image) === 1) {
                throw new InvalidArgumentException('Pixelforge reference images must be URL strings.');
            }

            $parts = parse_url($image);
            if (! is_array($parts)
                || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || ! isset($parts['host']) || $parts['host'] === '') {
                throw new InvalidArgumentException('Pixelforge reference images must be http(s) URLs.');
            }

            $images[] = $image;
        }

        return $images;
    }

    /** @return array{generation_id: string, key_fingerprint: string} */
    private static function metadata(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Pixelforge metadata must be an object.');
        }

        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['generation_id', 'key_fingerprint']) {
            throw new InvalidArgumentException('Pixelforge metadata must carry only the local generation id and key fingerprint.');
        }

        $generationId = $value['generation_id'];
        $fingerprint = $value['key_fingerprint'];

        if (! is_string($generationId) || trim($generationId) === '' || strlen($generationId) > 64
            || ! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Invalid Pixelforge correlation metadata.');
        }

        return [
            'generation_id' => $generationId,
            'key_fingerprint' => $fingerprint,
        ];
    }
}
