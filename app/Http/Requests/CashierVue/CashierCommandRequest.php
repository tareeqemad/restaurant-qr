<?php

namespace App\Http\Requests\CashierVue;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CashierPaymentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

abstract class CashierCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        $state = 'rejected';
        // An already committed payment stays recoverable if the enabled methods
        // changed while its acknowledgement was lost.
        if (($this instanceof PaymentRequest || $this instanceof SplitPaymentRequest)
            && $this->route('invoice') instanceof Invoice
            && is_string($this->input('token'))
            && $this->user()?->can('create', Payment::class)) {
            $result = DB::transaction(fn () => app(CashierPaymentService::class)->verify($this->route('invoice'), (int) $this->user()->id, $this->input('token'), true));
            if (($result['submission_state'] ?? null) === 'committed') {
                throw new HttpResponseException(response()->json(['ok' => true, 'message' => 'تم العثور على الدفعة المحفوظة.', 'data' => $result]));
            }
            // No receipt is not proof of failure: another request may not have
            // acquired its claim yet. Keep the recovery journal in that case.
            $state = 'unconfirmed';
        }
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'submission_state' => $state,
            'operation_token' => $this->input('token'),
            'message' => 'راجع الحقول المطلوبة ثم حاول مرة أخرى.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
