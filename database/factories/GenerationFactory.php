<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * A deterministic quote fixture, not live provider pricing.
     *
     * Fixture assumptions: "A lighthouse" is 12 characters / 3 text tokens,
     * medium square output is 1,100 tokens, reasoning is quoted at the 1,100
     * token midpoint, and the provider maxima are 4 text / 2,000 reasoning
     * tokens. Provider rates are kept as exact decimal strings:
     * text 5.00, reference image 10.00, output image 40.00, reasoning 12.50
     * per million tokens. The customer price is the provider cost x 5/4,
     * rounded half-up once:
     *
     * estimate: 15,000 + 44,000,000 + 13,750,000 = 57,765,000
     *           provider nanodollars x 5/4 = 72,206,250 customer nanodollars
     * maximum:  20,000 + 44,000,000 + 25,000,000 = 69,020,000
     *           provider nanodollars x 5/4 = 86,275,000 customer nanodollars
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'submission_token' => (string) Str::uuid7(),
            'prompt' => 'A lighthouse',
            'size' => GenerationSize::Square,
            'quality' => GenerationQuality::Medium,
            'reference_images' => [],
            'quote_snapshot' => [
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'tokens' => [
                    'input_text_tokens' => 3,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 1100,
                ],
                'bounds' => [
                    'text_max' => 4,
                    'reasoning_max' => 2000,
                ],
                'policy' => [
                    'currency' => 'USD',
                    'markup' => '5/4',
                    'rounding' => 'half_up',
                    'estimate_basis' => 'reasoning_midpoint',
                ],
            ],
            'estimated_cost_nanodollars' => 72_206_250,
            'maximum_cost_nanodollars' => 86_275_000,
        ];
    }
}
