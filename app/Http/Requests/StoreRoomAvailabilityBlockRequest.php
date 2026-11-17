<?php

namespace App\Http\Requests;

use App\Support\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoomAvailabilityBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');
        return $this->user()?->isAdmin() || ($room && $this->user()?->can('update', $room));
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value < BusinessTime::date()) {
                    $fail('The start date must be today or later in Philippine time.');
                }
            }],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
