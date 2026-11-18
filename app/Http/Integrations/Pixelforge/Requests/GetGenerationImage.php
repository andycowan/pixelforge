<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Fetch a generated image from its signed provider URL.
 *
 * Only URLs on the configured provider's own scheme and host are accepted, so
 * stored provider evidence can never make the server fetch an arbitrary URL.
 * The URL is already signed; callers use an anonymous connector (no API key).
 */
final class GetGenerationImage extends Request
{
    use HasTimeout;

    protected Method $method = Method::GET;

    /** Safe only because the constructor pins the URL to the provider's own host (Saloon v4 blocks absolute endpoints by default). */
    public ?bool $allowBaseUrlOverride = true;

    protected float $connectTimeout = 3.0;

    protected float $requestTimeout = 15.0;

    public function __construct(private readonly string $imageUrl)
    {
        $provider = parse_url((string) config('services.pixelforge.base_url'));
        $image = parse_url($imageUrl);
        if (! is_array($provider) || ! is_array($image)
            || strtolower($image['scheme'] ?? '') !== strtolower($provider['scheme'] ?? '')
            || strtolower($image['host'] ?? '') !== strtolower($provider['host'] ?? '')
            || ($image['port'] ?? null) !== ($provider['port'] ?? null)
            || isset($image['user']) || isset($image['pass'])) {
            throw new InvalidArgumentException('Image URL is not on the Pixelforge provider host.');
        }
    }

    /** A fully qualified endpoint overrides the connector's API base URL. */
    public function resolveEndpoint(): string
    {
        return $this->imageUrl;
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Accept' => 'image/png, image/jpeg, image/webp'];
    }
}
