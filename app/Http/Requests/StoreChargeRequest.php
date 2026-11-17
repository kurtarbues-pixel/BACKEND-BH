<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || ($this->user()?->can('charge', $this->route('lease')) ?? false);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'in:CANTEEN,APPLIANCE,OTHER'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'client_uuid' => ['nullable', 'uuid'],
            'billing_period' => ['nullable', 'date_format:Y-m'],
            'utility_type' => ['nullable', 'prohibited_unless:category,APPLIANCE', 'in:electricity,water,wifi'],
        ];
    }
}
