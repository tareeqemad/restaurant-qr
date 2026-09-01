<?php

namespace App\Http\Requests\CashierVue;

class CreateOrderRequest extends CashierCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:64'],
            // Cashier orders are phone orders by definition. Keeping these
            // server-owned prevents an old or forged client from attaching a
            // delivery obligation or fee to the bill.
            'order_type' => ['prohibited'],
            'order_source' => ['prohibited'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'delivery_address' => ['prohibited'],
            'delivery_fee' => ['prohibited'],
            'notes' => ['nullable', 'string', 'max:500'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'cart.*.quantity' => ['required', 'numeric', 'min:1'],
            'cart.*.modifier_ids' => ['present', 'array'],
            'cart.*.modifier_ids.*' => ['integer', 'exists:modifiers,id'],
            'cart.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cart.required' => 'أضف صنفاً واحداً على الأقل.',
            'customer_phone.required' => 'رقم هاتف الزبون مطلوب للطلب الهاتفي.',
            'order_type.prohibited' => 'نوع طلب الكاشير ثابت: طلب هاتفي للاستلام من المطعم.',
            'order_source.prohibited' => 'مصدر طلب الكاشير يُسجّل هاتفياً من الخادم.',
            'delivery_address.prohibited' => 'المطعم لا يدير عنوان توصيل لهذا الطلب.',
            'delivery_fee.prohibited' => 'لا تُضاف رسوم توصيل إلى فاتورة المطعم.',
        ];
    }
}
