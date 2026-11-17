<?php

namespace Tests\Unit;

use App\Data\PixelforgePricing;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\PricingException;
use App\Services\GenerationPricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenerationPricingTest extends TestCase
{
    public function test_the_quote_matches_the_documented_maximum_example(): void
    {
        $quote = (new GenerationPricing)->quote(
            str_repeat('a', 400),
            GenerationSize::Landscape,
            GenerationQuality::High,
            ['https://example.com/a.png', 'https://example.com/a.png'],
            PixelforgePricing::fromArray(self::catalogue()),
        );

        $this->assertSame([
            'input_text_tokens' => 100,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 4500,
        ], $quote->tokenEstimate);
        $this->assertSame(115, $quote->tokenMaximum['input_text_tokens']);
        $this->assertSame(8000, $quote->tokenMaximum['reasoning_tokens']);
        $this->assertSame(330_050_000, $quote->estimatedProviderCostNanodollars);
        $this->assertSame(373_875_000, $quote->maximumProviderCostNanodollars);
        $this->assertSame(412_562_500, $quote->estimatedCostNanodollars);
        $this->assertSame(467_343_750, $quote->maximumCostNanodollars);
        $this->assertSame('0.373875000', $quote->toArray()['provider_maximum_cost_usd']);
        $this->assertSame('0.467343750', $quote->toArray()['maximum_cost_usd']);
    }

    /** @return iterable<string, array{GenerationSize, GenerationQuality, int, int}> */
    public static function outputCases(): iterable
    {
        foreach (GenerationQuality::cases() as $quality) {
            $output = match ($quality) {
                GenerationQuality::Low => [280, 420],
                GenerationQuality::Medium => [1100, 1650],
                GenerationQuality::High => [4300, 6450],
            };
            $midpoint = match ($quality) {
                GenerationQuality::Low => 250,
                GenerationQuality::Medium => 1100,
                GenerationQuality::High => 4500,
            };
            foreach (GenerationSize::cases() as $size) {
                yield $quality->value.' '.$size->value => [$size, $quality, $output[$size === GenerationSize::Square ? 0 : 1], $midpoint];
            }
        }
    }

    #[DataProvider('outputCases')]
    public function test_each_size_and_quality_uses_the_published_rules(
        GenerationSize $size,
        GenerationQuality $quality,
        int $output,
        int $reasoning,
    ): void {
        $quote = (new GenerationPricing)->quote('A lighthouse', $size, $quality, [], PixelforgePricing::fromArray(self::catalogue()));

        $this->assertSame($output, $quote->tokenEstimate['output_tokens']);
        $this->assertSame($reasoning, $quote->tokenEstimate['reasoning_tokens']);
        $this->assertSame(15_000 + $output * 40_000 + $reasoning * 12_500, $quote->estimatedProviderCostNanodollars);
        $this->assertGreaterThanOrEqual($quote->estimatedCostNanodollars, $quote->maximumCostNanodollars);
    }

    public function test_fixture_quote_and_snapshot_are_reproducible_and_money_is_not_json_numbers(): void
    {
        $pricing = PixelforgePricing::fromArray(self::catalogue());
        $quote = (new GenerationPricing)->quote('A lighthouse', GenerationSize::Square, GenerationQuality::Medium, [], $pricing);

        $this->assertSame(72_206_250, $quote->estimatedCostNanodollars);
        $this->assertSame(86_275_000, $quote->maximumCostNanodollars);
        $this->assertSame('0.072206250', $quote->toArray()['estimated_cost_usd']);
        $this->assertSame('0.086275000', $quote->toArray()['maximum_cost_usd']);
        $this->assertSame('usd', $quote->toArray()['currency']);
        $this->assertSame(25, $quote->toArray()['markup_percent']);
        $this->assertSame($pricing->rates, $quote->snapshot()['rates']);
        $this->assertSame('5/4', $quote->snapshot()['policy']['markup']);
        $this->assertSame('0.15', $quote->snapshot()['token_rules']['text_token_variance']);
        $this->assertSame($pricing->toArray(), PixelforgePricing::fromArray($pricing->toArray())->toArray());
        $this->assertSame($quote->toArray(), json_decode(json_encode($quote->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($quote->toArray(), json_decode(json_encode($quote, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('snapshot', $quote->toArray());
        $this->assertArrayNotHasKey('estimated_cost_nanodollars', $quote->toArray());
    }

    public function test_text_tokens_count_unicode_characters_and_round_up(): void
    {
        $service = new GenerationPricing;
        $pricing = PixelforgePricing::fromArray(self::catalogue());
        $short = $service->quote(str_repeat('😀', 5), GenerationSize::Square, GenerationQuality::Low, [], $pricing);
        $long = $service->quote(str_repeat('😀', 4000), GenerationSize::Square, GenerationQuality::Low, [], $pricing);

        $this->assertSame(2, $short->tokenEstimate['input_text_tokens']);
        $this->assertSame(3, $short->tokenMaximum['input_text_tokens']);
        $this->assertSame(1000, $long->tokenEstimate['input_text_tokens']);
        $this->assertSame(1150, $long->tokenMaximum['input_text_tokens']);
    }

    public function test_duplicate_references_are_each_billed_and_odd_midpoints_round_up(): void
    {
        $payload = self::catalogue();
        $payload['tokens']['reasoning']['low'] = ['min' => 0, 'max' => 5];
        $quote = (new GenerationPricing)->quote('x', GenerationSize::Square, GenerationQuality::Low, [
            'https://example.com/a.png', 'https://example.com/a.png',
        ], PixelforgePricing::fromArray($payload));

        $this->assertSame(1530, $quote->tokenEstimate['input_image_tokens']);
        $this->assertSame(1530, $quote->tokenMaximum['input_image_tokens']);
        $this->assertSame(3, $quote->tokenEstimate['reasoning_tokens']);
        $this->assertSame(5, $quote->tokenMaximum['reasoning_tokens']);
    }

    public function test_actual_cost_uses_snapshot_rates_and_does_not_bill_total_tokens_again(): void
    {
        $service = new GenerationPricing;
        $rates = self::catalogue()['rates'];
        $usage = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];

        $this->assertSame(305_510_000, $service->providerCost($usage, $rates));
        $this->assertSame(381_887_500, $service->customerCost($service->providerCost($usage, $rates)));
        $newRates = array_fill_keys(PixelforgePricing::TOKEN_TYPES, '0.00');
        $this->assertSame(0, $service->providerCost($usage, $newRates));
        $this->assertSame(305_510_000, $service->providerCost($usage, $rates));
    }

    /** @return iterable<string, array{int, int}> */
    public static function markupCases(): iterable
    {
        yield 'zero' => [0, 0];
        yield 'one nanodollar' => [1, 1];
        yield 'half rounds up' => [2, 3];
        yield 'three quarters' => [3, 4];
        yield 'exact integer' => [4, 5];
        yield 'large exact input' => [9_007_199_254_740_993, 11_258_999_068_426_241];
    }

    #[DataProvider('markupCases')]
    public function test_markup_is_applied_once_with_half_up_rounding(int $provider, int $customer): void
    {
        $this->assertSame($customer, (new GenerationPricing)->customerCost($provider));
    }

    public function test_provider_components_are_summed_before_nano_precision_is_checked(): void
    {
        $rates = array_fill_keys(PixelforgePricing::TOKEN_TYPES, '0.0005');
        $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 1, 'output_tokens' => 0, 'reasoning_tokens' => 0];
        $this->assertSame(1, (new GenerationPricing)->providerCost($usage, $rates));

        $this->expectException(PricingException::class);
        (new GenerationPricing)->providerCost([...$usage, 'input_image_tokens' => 0], $rates);
    }

    public function test_usage_categories_are_not_individually_marked_up_or_rounded(): void
    {
        $rates = array_fill_keys(PixelforgePricing::TOKEN_TYPES, '0.001');
        $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 1, 'output_tokens' => 0, 'reasoning_tokens' => 0];
        $service = new GenerationPricing;
        $this->assertSame(3, $service->customerCost($service->providerCost($usage, $rates)));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidCatalogueCases(): iterable
    {
        yield 'wrong object' => ['object', 'generation'];
        yield 'wrong currency' => ['currency', 'eur'];
        yield 'wrong unit' => ['unit', 'per_token'];
        yield 'rates not object' => ['rates', []];
        yield 'missing rate' => ['rates.input_text_tokens', null];
        yield 'float rate' => ['rates.output_tokens', 40.0];
        yield 'negative rate' => ['rates.output_tokens', '-1.00'];
        yield 'scientific monetary rate' => ['rates.output_tokens', '4e1'];
        yield 'oversized rate' => ['rates.output_tokens', str_repeat('1', 65)];
        yield 'unknown rate category' => ['rates.cached_tokens', '2.00'];
        yield 'tokens not object' => ['tokens', []];
        yield 'zero characters per token' => ['tokens.characters_per_text_token', 0];
        yield 'fractional characters per token' => ['tokens.characters_per_text_token', 4.0];
        yield 'negative variance' => ['tokens.text_token_variance', -0.15];
        yield 'boolean variance' => ['tokens.text_token_variance', true];
        yield 'oversized normalised variance' => ['tokens.text_token_variance', '1e100'];
        yield 'negative reference cost' => ['tokens.per_reference_image', -1];
        yield 'missing output row' => ['tokens.output.medium', null];
        yield 'fractional output count' => ['tokens.output.medium.1024x1024', 1.5];
        yield 'missing reasoning range' => ['tokens.reasoning.high', null];
        yield 'reversed range' => ['tokens.reasoning.high.max', 2];
    }

    #[DataProvider('invalidCatalogueCases')]
    public function test_invalid_provider_catalogues_fail_closed(string $path, mixed $value): void
    {
        $payload = self::catalogue();
        $cursor = &$payload;
        foreach (explode('.', $path) as $key) {
            $cursor = &$cursor[$key];
        }
        $cursor = $value;
        unset($cursor);

        $this->expectException(PricingException::class);
        PixelforgePricing::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidUsageCases(): iterable
    {
        yield 'missing category' => [['input_text_tokens' => null]];
        yield 'negative tokens' => [['output_tokens' => -1]];
        yield 'float tokens' => [['output_tokens' => 1.0]];
        yield 'string tokens' => [['output_tokens' => '1']];
        yield 'unknown category' => [['cached_tokens' => 1]];
        yield 'inconsistent total' => [['total_tokens' => 999]];
        yield 'float total' => [['total_tokens' => 0.0]];
    }

    #[DataProvider('invalidUsageCases')]
    public function test_invalid_usage_cannot_produce_a_charge(array $overrides): void
    {
        $usage = array_fill_keys(PixelforgePricing::TOKEN_TYPES, 0);
        $this->expectException(PricingException::class);
        (new GenerationPricing)->providerCost([...$usage, ...$overrides], self::catalogue()['rates']);
    }

    public function test_integer_overflow_is_rejected_instead_of_becoming_float_money(): void
    {
        $this->expectException(PricingException::class);
        (new GenerationPricing)->customerCost(PHP_INT_MAX);
    }

    public function test_provider_cost_overflow_is_rejected(): void
    {
        $usage = [...array_fill_keys(PixelforgePricing::TOKEN_TYPES, 0), 'output_tokens' => PHP_INT_MAX];
        $this->expectException(PricingException::class);
        (new GenerationPricing)->providerCost($usage, self::catalogue()['rates']);
    }

    public function test_token_rule_overflow_is_rejected(): void
    {
        $payload = self::catalogue();
        $payload['tokens']['per_reference_image'] = PHP_INT_MAX;
        $this->expectException(PricingException::class);
        (new GenerationPricing)->quote('x', GenerationSize::Square, GenerationQuality::Low, ['a', 'b'], PixelforgePricing::fromArray($payload));
    }

    public function test_zero_provider_usage_costs_zero(): void
    {
        $this->assertSame(0, (new GenerationPricing)->providerCost(array_fill_keys(PixelforgePricing::TOKEN_TYPES, 0), self::catalogue()['rates']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidProviderCosts(): iterable
    {
        yield 'negative' => [-1];
        yield 'float' => [1.5];
        yield 'numeric string' => ['100'];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    #[DataProvider('invalidProviderCosts')]
    public function test_provider_money_is_not_weakly_coerced(mixed $cost): void
    {
        $this->expectException(PricingException::class);
        (new GenerationPricing)->customerCost($cost);
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function invalidQuoteInputs(): iterable
    {
        yield 'blank' => ['   ', []];
        yield 'too long Unicode prompt' => [str_repeat('😀', 4001), []];
        yield 'invalid UTF8' => ["\xff", []];
        yield 'too many references' => ['x', array_fill(0, 5, 'https://example.com/a.png')];
        yield 'references not a list' => ['x', ['first' => 'https://example.com/a.png']];
        yield 'references not strings' => ['x', [1]];
    }

    #[DataProvider('invalidQuoteInputs')]
    public function test_invalid_quote_inputs_are_rejected(string $prompt, array $references): void
    {
        $this->expectException(PricingException::class);
        (new GenerationPricing)->quote($prompt, GenerationSize::Square, GenerationQuality::Medium, $references, PixelforgePricing::fromArray(self::catalogue()));
    }

    /** @return array<string, mixed> */
    private static function catalogue(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
