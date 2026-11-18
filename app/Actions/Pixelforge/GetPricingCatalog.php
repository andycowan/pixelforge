<?php

namespace App\Actions\Pixelforge;

use App\Data\PixelforgePricing;
use App\Exceptions\PricingException;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Saloon\Exceptions\Request\FatalRequestException;

final class GetPricingCatalog
{
    public const CACHE_KEY = 'pixelforge.pricing.v1';

    public const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly PixelforgeConnector $connector,
        private readonly CacheRepository $cache,
    ) {}

    public function get(): PixelforgePricing
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            try {
                return PixelforgePricing::fromArray($cached);
            } catch (PricingException) {
                $this->cache->forget(self::CACHE_KEY);
            }
        } elseif ($cached !== null) {
            $this->cache->forget(self::CACHE_KEY);
        }

        $pricing = PixelforgePricing::fromArray($this->fetchPayload());

        $this->cache->put(self::CACHE_KEY, $pricing->toArray(), self::CACHE_TTL_SECONDS);

        return $pricing;
    }

    /** @return array<string, mixed> */
    private function fetchPayload(): array
    {
        try {
            $response = $this->connector->send(new GetPricing);
        } catch (FatalRequestException $exception) {
            throw new PricingException('The Pixelforge pricing catalogue could not be reached.', 0, $exception);
        }

        if ($response->failed()) {
            throw new PricingException(sprintf('The Pixelforge pricing catalogue returned HTTP %d.', $response->status()));
        }

        $payload = json_decode($response->body(), true);

        if (! is_array($payload)) {
            throw new PricingException('The Pixelforge pricing catalogue was not a JSON object.');
        }

        return $payload;
    }
}
