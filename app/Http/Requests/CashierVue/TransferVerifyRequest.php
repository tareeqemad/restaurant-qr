<?php

namespace App\Http\Requests\CashierVue;

class TransferVerifyRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'verified_amount' => ['nullable', 'numeric', 'min:0.01'],
            'verification_notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
