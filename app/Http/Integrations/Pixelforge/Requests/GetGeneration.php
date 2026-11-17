<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderGeneration;
use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/**
 * Retrieve one provider generation for reconciliation.
 *
 * A single attempt: scheduling and retry limits belong to the caller.
 */
final class GetGeneration extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $providerId)
    {
        if (preg_match('/\Agen_[A-Za-z0-9]{1,64}\z/', $providerId) !== 1) {
            throw new InvalidArgumentException('Invalid Pixelforge generation id.');
        }
    }

    public function resolveEndpoint(): string
    {
        return 'generations/'.$this->providerId;
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
}
