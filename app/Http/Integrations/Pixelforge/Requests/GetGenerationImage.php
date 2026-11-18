<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\HasTimeout;

final class GetGenerationImage extends Request
{
    use HasTimeout;

    protected Method $method = Method::GET;

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
