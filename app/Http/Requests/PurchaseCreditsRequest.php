<?php

namespace App\Http\Requests;

use App\Actions\Credits\PurchaseCredits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PurchaseCreditsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'package' => ['required', 'string', Rule::in(array_keys(PurchaseCredits::PACKAGES))],
            'idempotency_token' => ['required', 'string', 'uuid'],
        ];
    }
}
