<?php

namespace App\Http\Integrations\Pixelforge;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

final class PixelforgeConnector extends Connector
{
    use HasTimeout;

    private readonly ?string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $apiKey = $apiKey === null ? null : trim($apiKey);
        $this->apiKey = $apiKey === '' ? null : $apiKey;
    }

    protected float $connectTimeout = 3.0;

    protected float $requestTimeout = 5.0;

    public static function configuredKey(): ?string
    {
        $key = config('services.pixelforge.api_key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    public function resolveBaseUrl(): string
    {
        return rtrim((string) config('services.pixelforge.base_url'), '/');
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    protected function defaultAuth(): ?Authenticator
    {
        return $this->apiKey === null ? null : new TokenAuthenticator($this->apiKey);
    }
}
