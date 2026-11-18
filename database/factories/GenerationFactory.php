<?php

namespace Database\Factories;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
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
                    'input_text_tokens' => 4,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 2000,
                ],
                'policy' => [
                    'currency' => 'USD',
                    'markup' => '5/4',
                    'rounding' => 'ceiling',
                    'precision' => 2,
                    'estimate_basis' => 'reasoning_midpoint_rounded_up',
                ],
            ],
            'estimated_cost_nanodollars' => 80_000_000,
            'maximum_cost_nanodollars' => 90_000_000,
        ];
    }
}
