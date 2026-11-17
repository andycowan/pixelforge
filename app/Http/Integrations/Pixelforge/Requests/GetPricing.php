<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Read the public pricing catalogue.
 *
 * A single attempt: retrying belongs to the caller, not to an implicit
 * Saloon retry that sleeps inside a web request.
 */
final class GetPricing extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'pricing';
    }
}
