<?php

namespace App\Http\Requests;

use Closure;

final class StoreGenerationRequest extends GenerationQuoteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['prompt'][] = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail('The prompt contains an unsupported character.');
            }
        };

        $rules['submission_token'] = ['required', 'string', 'uuid'];

        return $rules;
    }
}
