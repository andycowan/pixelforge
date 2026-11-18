<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class OperatorReportRequest extends FormRequest
{
    public const MAX_DAYS = 92;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            [$start, $end] = $this->range();
            if ($end->isAfter(CarbonImmutable::today('UTC'))) {
                $validator->errors()->add('end_date', 'The end date cannot be in the future.');
            } elseif ($start->isAfter($end)) {
                $validator->errors()->add('start_date', 'The start date must be on or before the end date.');
            } elseif ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('start_date', 'Choose a range of at most '.self::MAX_DAYS.' days.');
            }
        }];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        $end = $this->filled('end_date') ? CarbonImmutable::parse((string) $this->string('end_date'), 'UTC')->startOfDay() : CarbonImmutable::today('UTC');
        $start = $this->filled('start_date') ? CarbonImmutable::parse((string) $this->string('start_date'), 'UTC')->startOfDay() : $end->subDays(29);

        return [$start, $end];
    }
}
