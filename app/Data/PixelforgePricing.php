<?php

namespace App\Data;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\PricingException;
use Brick\Math\BigDecimal;

final readonly class PixelforgePricing
{
    public const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * @param  array<string, string>  $rates
     * @param  array<string, mixed>  $tokens
     */
    private function __construct(public array $rates, public array $tokens) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (($payload['object'] ?? null) !== 'pricing'
            || ($payload['currency'] ?? null) !== 'usd'
            || ($payload['unit'] ?? null) !== 'per_1m_tokens') {
            throw new PricingException('Unsupported provider pricing envelope, currency or unit.');
        }

        $rates = self::validateRates(self::object($payload['rates'] ?? null, 'rates'));
        $tokens = self::object($payload['tokens'] ?? null, 'tokens');
        $output = self::object($tokens['output'] ?? null, 'tokens.output');
        $reasoning = self::object($tokens['reasoning'] ?? null, 'tokens.reasoning');

        $normalised = [
            'characters_per_text_token' => self::integer($tokens['characters_per_text_token'] ?? null, 'characters_per_text_token', positive: true),
            'text_token_variance' => self::variance($tokens['text_token_variance'] ?? null),
            'per_reference_image' => self::integer($tokens['per_reference_image'] ?? null, 'per_reference_image'),
            'output' => [],
            'reasoning' => [],
        ];

        foreach (GenerationQuality::cases() as $quality) {
            $outputs = self::object($output[$quality->value] ?? null, "output.{$quality->value}");
            foreach (GenerationSize::cases() as $size) {
                $normalised['output'][$quality->value][$size->value] = self::integer(
                    $outputs[$size->value] ?? null,
                    "output.{$quality->value}.{$size->value}",
                );
            }

            $range = self::object($reasoning[$quality->value] ?? null, "reasoning.{$quality->value}");
            $min = self::integer($range['min'] ?? null, "reasoning.{$quality->value}.min");
            $max = self::integer($range['max'] ?? null, "reasoning.{$quality->value}.max");
            if ($max < $min) {
                throw new PricingException('Reasoning token bounds are reversed.');
            }
            $normalised['reasoning'][$quality->value] = ['min' => $min, 'max' => $max];
        }

        return new self($rates, $normalised);
    }

    /**
     * Monetary rates must remain decimal strings; never accept JSON float rates.
     *
     * @param  array<string, mixed>  $rates
     * @return array<string, string>
     */
    public static function validateRates(array $rates): array
    {
        if (count($rates) !== count(self::TOKEN_TYPES)
            || array_diff(self::TOKEN_TYPES, array_keys($rates)) !== []) {
            throw new PricingException('Provider rates must price exactly the four supported token categories.');
        }

        $validated = [];
        foreach (self::TOKEN_TYPES as $type) {
            $rate = $rates[$type];
            if (! is_string($rate) || strlen($rate) > 64
                || preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $rate) !== 1) {
                throw new PricingException("Invalid decimal-string provider rate for [{$type}].");
            }
            $validated[$type] = $rate;
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => $this->rates,
            'tokens' => $this->tokens,
        ];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new PricingException("Provider [{$field}] must be an object.");
        }

        return $value;
    }

    private static function integer(mixed $value, string $field, bool $positive = false): int
    {
        if (! is_int($value) || $value < ($positive ? 1 : 0)) {
            throw new PricingException("Invalid provider integer rule [{$field}].");
        }

        return $value;
    }

    private static function variance(mixed $value): string
    {
        // Variance is a JSON numeric token rule, not a monetary rate.
        if (is_float($value) && is_finite($value)) {
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || strlen($value) > 64
            || preg_match('/\A[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]{1,3})?\z/D', $value) !== 1) {
            throw new PricingException('Invalid provider text token variance.');
        }

        $normalised = (string) BigDecimal::of($value)->strippedOfTrailingZeros();
        if (strlen($normalised) > 64) {
            throw new PricingException('Provider text token variance exceeds supported precision.');
        }

        return $normalised;
    }
}
