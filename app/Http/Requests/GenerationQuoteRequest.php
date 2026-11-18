<?php

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerationQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prompt' => [
                'required',
                'string',
                'max:4000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || preg_match('/\S/u', $value) !== 1) {
                        $fail('The prompt must contain at least one non-whitespace character.');
                    }
                },
            ],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['sometimes', 'array', 'list', 'max:4'],
            'reference_images.*' => ['string', 'url:http,https'],
        ];
    }
}
