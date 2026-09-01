<?php

namespace App\Http\Requests\CashierVue;

class SettleOnAccountRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
