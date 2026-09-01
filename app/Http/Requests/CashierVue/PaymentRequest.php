<?php

namespace App\Http\Requests\CashierVue;

use App\Support\PaymentMethods;
use Illuminate\Validation\Rule;

class PaymentRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in([...PaymentMethods::enabled(), PaymentMethods::CUSTOMER_ADVANCE])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tendered_amount' => ['nullable', 'numeric', 'min:0.01'],
            'save_change_as_advance' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'المبلغ',
            'method' => 'طريقة الدفع',
            'reference' => 'المرجع',
            'notes' => 'الملاحظات',
            'tendered_amount' => 'المبلغ المستلم',
        ];
    }
}
