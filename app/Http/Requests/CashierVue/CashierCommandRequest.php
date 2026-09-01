<?php

namespace App\Http\Requests\CashierVue;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class CashierCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'راجع الحقول المطلوبة ثم حاول مرة أخرى.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
