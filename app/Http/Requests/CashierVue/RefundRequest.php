<?php

namespace App\Http\Requests\CashierVue;

use App\Models\Refund;
use Illuminate\Validation\Rule;

class RefundRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(Refund::ACTIVE_METHODS)],
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.disposition' => ['nullable', Rule::in(['none', 'waste', 'restock'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'المبلغ',
            'method' => 'طريقة الاسترداد',
            'reason' => 'السبب',
            'reference' => 'رقم المرجع',
            'notes' => 'الملاحظات',
            'lines' => 'بنود الإرجاع',
            'lines.*.quantity' => 'الكمية المرتجعة',
        ];
    }
}
