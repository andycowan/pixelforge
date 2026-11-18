<?php

namespace App\Http\Integrations\Pixelforge\Data;

use App\Exceptions\ProviderPayloadException;
use Carbon\CarbonImmutable;

/**
 * Validated provider usage for an inclusive UTC date range.
 *
 * Costs become exact integer nanodollars; every requested day must be present
 * exactly once, and the provider's own total must equal the sum of its days.
 */
final readonly class ProviderUsage
{
    public const FIELDS = ['generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens', 'cost_nanodollars'];

    /** @param array<string, array<string, int>> $days date => FIELDS => value */
    private function __construct(public array $days) {}

    public static function fromArray(mixed $payload, CarbonImmutable $start, CarbonImmutable $end): self
    {
        if (! is_array($payload) || ($payload['object'] ?? null) !== 'usage'
            || ($payload['start_date'] ?? null) !== $start->toDateString() || ($payload['end_date'] ?? null) !== $end->toDateString()
            || ! is_array($payload['data'] ?? null) || ! array_is_list($payload['data']) || ! is_array($payload['total'] ?? null)) {
            throw new ProviderPayloadException('Invalid Pixelforge usage envelope.');
        }

        $days = [];
        foreach ($payload['data'] as $row) {
            $date = is_array($row) ? ($row['date'] ?? null) : null;
            if (! is_string($date) || isset($days[$date])) {
                throw new ProviderPayloadException('Invalid or duplicate Pixelforge usage day.');
            }
            $days[$date] = self::figures($row);
        }
        $expected = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $expected[] = $day->toDateString();
        }
        if (array_keys($days) !== $expected) {
            throw new ProviderPayloadException('Pixelforge usage days do not cover the requested range.');
        }

        $sum = array_fill_keys(self::FIELDS, 0);
        foreach ($days as $figures) {
            foreach (self::FIELDS as $field) {
                $sum[$field] += $figures[$field];
            }
        }
        if (self::figures($payload['total']) !== $sum) {
            throw new ProviderPayloadException('Pixelforge usage total does not equal the sum of its days.');
        }

        return new self($days);
    }

    /** @return array<string, int> */
    private static function figures(mixed $row): array
    {
        if (! is_array($row)) {
            throw new ProviderPayloadException('Invalid Pixelforge usage figures.');
        }
        $figures = [];
        foreach (array_slice(self::FIELDS, 0, 5) as $field) {
            $value = $row[$field] ?? null;
            if (! is_int($value) || $value < 0) {
                throw new ProviderPayloadException("Invalid Pixelforge usage field [{$field}].");
            }
            $figures[$field] = $value;
        }
        $cost = $row['cost_usd'] ?? null;
        // Exactly nine decimal places, as documented: the digits are the nanodollars.
        if (! is_string($cost) || preg_match('/\A(0|[1-9]\d{0,8})\.(\d{9})\z/D', $cost, $parts) !== 1) {
            throw new ProviderPayloadException('Invalid Pixelforge usage cost.');
        }
        $figures['cost_nanodollars'] = (int) $parts[1] * 1_000_000_000 + (int) $parts[2];

        return $figures;
    }
}
