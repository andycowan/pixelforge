<?php

namespace App\Actions\Pixelforge;

use App\Data\PixelforgePricing;
use App\Exceptions\PricingException;
use App\Http\Integrations\Pixelforge\PixelforgeConnector;
use App\Http\Integrations\Pixelforge\Requests\GetPricing;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Saloon\Exceptions\Request\FatalRequestException;

/**
 * Obtain the validated provider pricing catalogue.
 *
 * Only a validated catalogue array is cached: cache hits are re-validated, a
 * malformed cache entry is evicted and refetched once, and failures are never
 * cached or served from an expired entry.
 */
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
                // A stored catalogue that no longer validates must never be
                // served. Discard it and fall through to one fresh fetch.
                $this->cache->forget(self::CACHE_KEY);
            }
        } elseif ($cached !== null) {
            $this->cache->forget(self::CACHE_KEY);
        }

        $pricing = PixelforgePricing::fromArray($this->fetchPayload());

        $this->cache->put(self::CACHE_KEY, $pricing->toArray(), self::CACHE_TTL_SECONDS);

        return $pricing;
    }

    /**
     * @return array<string, mixed>
     */
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

        // Decode without throwing so non-JSON, empty and scalar bodies are all
        // reported as an unavailable catalogue rather than a PHP error.
        $payload = json_decode($response->body(), true);

        if (! is_array($payload)) {
            throw new PricingException('The Pixelforge pricing catalogue was not a JSON object.');
        }

        return $payload;
    }
}
