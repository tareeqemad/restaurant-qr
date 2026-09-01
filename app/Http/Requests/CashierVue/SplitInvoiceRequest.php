<?php

namespace App\Http\Requests\CashierVue;

use App\Support\PaymentMethods;

class SplitInvoiceRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'splits' => ['required', 'array', 'min:2'],
            'splits.*.label' => ['nullable', 'string', 'max:255'],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.method' => ['required', PaymentMethods::inRule()],
        ];
    }
}
