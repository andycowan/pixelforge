<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use App\Exceptions\ProviderPayloadException;
use App\Http\Integrations\Pixelforge\Data\ProviderUsage;
use Carbon\CarbonImmutable;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

final class GetUsage extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly CarbonImmutable $start, private readonly CarbonImmutable $end) {}

    public function resolveEndpoint(): string
    {
        return 'usage';
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return ['start_date' => $this->start->toDateString(), 'end_date' => $this->end->toDateString()];
    }

    public function createDtoFromResponse(Response $response): ProviderUsage
    {
        try {
            /** @var mixed $payload */
            $payload = $response->json();
        } catch (Throwable $exception) {
            throw new ProviderPayloadException('Invalid Pixelforge usage response.', previous: $exception);
        }

        return ProviderUsage::fromArray($payload, $this->start, $this->end);
    }
}
