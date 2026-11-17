<?php

namespace App\Http\Integrations\Pixelforge;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Pixelforge provider API. Pricing is public, so a connector created without
 * an explicit key stays anonymous; generation calls pass the configured key.
 */
final class PixelforgeConnector extends Connector
{
    use HasTimeout;

    private readonly ?string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $apiKey = $apiKey === null ? null : trim($apiKey);
        $this->apiKey = $apiKey === '' ? null : $apiKey;
    }

    /**
     * Bound provider calls tightly; no automatic Saloon retries are
     * configured, so submission timing stays with the worker.
     */
    protected float $connectTimeout = 3.0;

    protected float $requestTimeout = 5.0;

    public function resolveBaseUrl(): string
    {
        return rtrim((string) config('services.pixelforge.base_url'), '/');
    }

    /**
     * @return array<string, string>
     */
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
