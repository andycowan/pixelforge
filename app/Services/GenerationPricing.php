<?php

namespace App\Services;

use App\Data\GenerationQuote;
use App\Data\PixelforgePricing;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\PricingException;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final class GenerationPricing
{
    public const CENT = 10_000_000;

    /** @param array<array-key, mixed> $referenceImages */
    public function quote(
        string $prompt,
        GenerationSize $size,
        GenerationQuality $quality,
        array $referenceImages,
        PixelforgePricing $pricing,
    ): GenerationQuote {
        if (! mb_check_encoding($prompt, 'UTF-8') || preg_match('/\S/u', $prompt) !== 1
            || mb_strlen($prompt, 'UTF-8') > 4000) {
            throw new PricingException('A quote requires a nonblank prompt of at most 4000 Unicode characters.');
        }
        if (! array_is_list($referenceImages) || count($referenceImages) > 4) {
            throw new PricingException('A quote supports at most four reference images.');
        }
        foreach ($referenceImages as $reference) {
            if (! is_string($reference)) {
                throw new PricingException('Quote references must be URL strings.');
            }
        }

        $rules = $pricing->tokens;
        $characters = mb_strlen($prompt, 'UTF-8');
        $perToken = $rules['characters_per_text_token'];
        $text = intdiv($characters, $perToken) + (int) ($characters % $perToken !== 0);
        $range = $rules['reasoning'][$quality->value];
        $difference = $range['max'] - $range['min'];
        $reasoning = $range['min'] + intdiv($difference, 2) + ($difference % 2);

        try {
            $images = BigInteger::of($rules['per_reference_image'])->multipliedBy(count($referenceImages))->toInt();
            $maxText = BigDecimal::of($text)
                ->multipliedBy(BigDecimal::of(1)->plus($rules['text_token_variance']))
                ->toScale(0, RoundingMode::Ceiling)
                ->toInt();
        } catch (MathException $exception) {
            throw new PricingException('Provider token rules exceed the supported integer range.', previous: $exception);
        }

        $estimate = [
            'input_text_tokens' => $text,
            'input_image_tokens' => $images,
            'output_tokens' => $rules['output'][$quality->value][$size->value],
            'reasoning_tokens' => $reasoning,
        ];
        $maximum = [
            ...$estimate,
            'input_text_tokens' => $maxText,
            'reasoning_tokens' => $range['max'],
        ];
        $estimatedProvider = $this->providerCost($estimate, $pricing->rates);
        $maximumProvider = $this->providerCost($maximum, $pricing->rates);

        return new GenerationQuote(
            estimatedCostNanodollars: $this->customerCost($estimatedProvider),
            maximumCostNanodollars: $this->customerCost($maximumProvider),
            estimatedProviderCostNanodollars: $estimatedProvider,
            maximumProviderCostNanodollars: $maximumProvider,
            tokenEstimate: $estimate,
            tokenMaximum: $maximum,
            pricing: $pricing,
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     */
    public function providerCost(array $usage, array $rates): int
    {
        $rates = PixelforgePricing::validateRates($rates);
        if (array_diff(array_keys($usage), [...PixelforgePricing::TOKEN_TYPES, 'total_tokens']) !== []) {
            throw new PricingException('Unsupported provider usage category.');
        }

        $total = BigDecimal::of(0);
        $tokens = BigInteger::of(0);

        foreach (PixelforgePricing::TOKEN_TYPES as $type) {
            $count = $usage[$type] ?? null;
            if (! is_int($count) || $count < 0) {
                throw new PricingException("Invalid provider token count [{$type}].");
            }
            $tokens = $tokens->plus($count);
            $total = $total->plus(BigDecimal::of($rates[$type])->multipliedBy($count));
        }

        if (array_key_exists('total_tokens', $usage)
            && (! is_int($usage['total_tokens']) || $usage['total_tokens'] < 0
                || ! $tokens->isEqualTo($usage['total_tokens']))) {
            throw new PricingException('Provider total_tokens is inconsistent with the billed categories.');
        }

        try {
            return $total->multipliedBy(1000)->toBigInteger()->toInt();
        } catch (MathException $exception) {
            throw new PricingException('Provider cost is not exactly representable in supported nanodollars.', previous: $exception);
        }
    }

    public function customerCost(mixed $providerCost): int
    {
        if (! is_int($providerCost) || $providerCost < 0) {
            throw new PricingException('Provider cost must be a nonnegative integer number of nanodollars.');
        }

        try {
            return BigDecimal::of($providerCost)->multipliedBy(5)
                ->dividedBy(4 * self::CENT, scale: 0, roundingMode: RoundingMode::Ceiling)
                ->multipliedBy(self::CENT)
                ->toInt();
        } catch (MathException $exception) {
            throw new PricingException('Customer cost exceeds the supported integer range.', previous: $exception);
        }
    }

    public static function formatUsd(int $nanodollars): string
    {
        $magnitude = abs($nanodollars);

        return ($nanodollars < 0 ? '-' : '').sprintf('%d.%09d', intdiv($magnitude, 1_000_000_000), $magnitude % 1_000_000_000);
    }

    public static function formatDollars(int $nanodollars): string
    {
        $cents = intdiv(abs($nanodollars), self::CENT);

        return ($nanodollars < 0 ? '-' : '').sprintf('$%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
