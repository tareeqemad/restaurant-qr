<?php

namespace App\Http\Requests\CashierVue;

class TokenRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
        ];
    }
}
