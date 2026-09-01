<?php

namespace App\Http\Requests\CashierVue;


class DiscountRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'category_lookup_id' => ['nullable', 'integer', 'exists:lookups,id'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'اختر نوع الخصم.',
            'value.required' => 'أدخل قيمة الخصم.',
            'reason.required' => 'سبب الخصم إلزامي.',
        ];
    }
}
