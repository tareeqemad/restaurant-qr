<?php

namespace App\Http\Requests\CashierVue;

class CreateCustomerRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم الزبون مطلوب.',
            'phone.required' => 'رقم جوال الزبون مطلوب.',
        ];
    }
}
