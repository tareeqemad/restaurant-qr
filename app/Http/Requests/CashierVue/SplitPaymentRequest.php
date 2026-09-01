<?php

namespace App\Http\Requests\CashierVue;

class SplitPaymentRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
