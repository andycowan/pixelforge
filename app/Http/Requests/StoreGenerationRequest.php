<?php

namespace App\Http\Requests;

use Closure;

/**
 * HTTP validation for a real generation request.
 *
 * Reuses the quote rules verbatim — the same options are priced — and adds the
 * browser-owned deduplication token. Money amounts, quote snapshots, the API
 * key and the owner are not accepted fields at all; they are decided on the
 * server.
 */
final class StoreGenerationRequest extends GenerationQuoteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // Postgres text cannot store a NUL byte, and the provider rejects it.
        // Fail it as a prompt error rather than as a database or provider error.
        $rules['prompt'][] = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail('The prompt contains an unsupported character.');
            }
        };

        $rules['submission_token'] = ['required', 'string', 'uuid'];

        return $rules;
    }
}
