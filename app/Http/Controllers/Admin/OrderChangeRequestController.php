<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderChangeRequest;
use App\Services\OrderChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderChangeRequestController extends Controller
{
    public function resolve(Request $request, OrderChangeRequest $changeRequest, OrderChangeRequestService $service)
    {
        $changeRequest->loadMissing('order');
        $this->authorize('cancel', $changeRequest->order);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'disposition' => ['nullable', Rule::in(['return', 'waste'])],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
            'expected_started' => ['nullable', 'boolean'],
        ]);

        try {
            $service->resolve(
                $changeRequest,
                auth()->id(),
                $data['decision'],
                $data['disposition'] ?? 'return',
                $data['resolution_note'] ?? null,
                array_key_exists('expected_started', $data) ? (bool) $data['expected_started'] : null,
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['decision'] === 'approve'
            ? 'تم تنفيذ طلب الزبون وتحديث الطلب.'
            : 'تم رفض الطلب وإبلاغ الزبون.');
    }
}
