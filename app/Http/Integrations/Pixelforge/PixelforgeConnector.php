<?php

namespace App\Http\Integrations\Pixelforge;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Pixelforge provider API. The pricing endpoint is public, so this phase
 * deliberately carries no credentials.
 */
final class PixelforgeConnector extends Connector
{
    use HasTimeout;

    /**
     * Bound a pricing call tightly: this work sits in front of a browser
     * request, and no automatic Saloon retries are configured.
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
}
