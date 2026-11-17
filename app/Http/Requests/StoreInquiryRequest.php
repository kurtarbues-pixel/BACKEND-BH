<?php

namespace App\Http\Requests;

use App\Models\Inquiry;
use App\Support\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Inquiry::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'desired_start_date' => [
                'nullable', 'date_format:Y-m-d', 'required_with:desired_end_date',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value && is_string($value) && $value < BusinessTime::date()) {
                        $fail('The desired start date must be today or later in Philippine time.');
                    }
                },
            ],
            'desired_end_date' => ['nullable', 'date', 'after_or_equal:desired_start_date'],
        ];
    }
}
