<?php

namespace App\Http\Requests\CashierVue;

use App\Support\PaymentMethods;
use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

class CustomerAdvanceDepositRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'regex:/^0\d{9}$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'method' => ['required', Rule::in(PaymentMethods::enabled())],
            'reference' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'phone' => 'رقم الجوال',
            'name' => 'اسم الزبون',
            'amount' => 'قيمة الرصيد',
            'method' => 'طريقة الاستلام',
            'reference' => 'المرجع',
            'notes' => 'الملاحظات',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'أدخل رقم جوال فلسطينياً من 10 أرقام مثل 0592632026.',
        ];
    }
}
