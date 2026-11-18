<?php

namespace App\Http\Integrations\Pixelforge\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

final class GetPricing extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'pricing';
    }
}
