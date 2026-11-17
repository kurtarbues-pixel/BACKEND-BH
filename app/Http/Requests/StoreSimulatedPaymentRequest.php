<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSimulatedPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $this->user()?->isTenant()
            && $invoice instanceof Invoice
            && $invoice->lease()->where('tenant_id', $this->user()->id)->exists();
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'method' => ['required', Rule::in(['gcash_simulated', 'maya_simulated', 'bank_transfer_simulated'])],
            'client_uuid' => ['required', 'uuid'],
            'simulate_failure' => ['sometimes', 'boolean'],
        ];
    }
}
