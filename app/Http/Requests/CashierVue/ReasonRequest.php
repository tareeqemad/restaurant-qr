<?php

namespace App\Http\Requests\CashierVue;

class ReasonRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
