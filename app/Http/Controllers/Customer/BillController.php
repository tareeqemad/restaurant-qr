<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function show(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing(['table.branch', 'invoice.payments']);
        $orders = $session->orders()->with('items.modifiers')->whereNotIn('status', ['cancelled'])->get();
        $totals = [
            'subtotal' => $orders->sum('subtotal'),
            'tax' => $orders->sum('tax_total'),
            'service' => $orders->sum('service_total'),
            'total' => $orders->sum('total'),
        ];
        $invoice = $session->invoice;

        return view('customer.bill', compact('orders', 'totals', 'session', 'invoice'));
    }

    public function requestBill(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing(['table', 'invoice']);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($session->invoice && ! in_array($session->invoice->status, ['cancelled'], true)) {
            return back()->with('info', 'الفاتورة صدرت بالفعل. الكاشير يتابع الدفع الآن.');
        }

        if ($session->bill_requested_at && $session->bill_requested_at->gt(now()->subMinutes(2))) {
            return back()->with('info', 'طلب الفاتورة وصل للكاشير بالفعل.');
        }

        $session->update([
            'bill_requested_at' => now(),
            'bill_request_note' => $data['note'] ?? null,
        ]);
        $session->touch();

        ActivityLog::log(
            'session.bill_requested',
            "طلب فاتورة من طاولة {$session->table?->number}",
            $session,
            ['note' => $data['note'] ?? null]
        );

        // Notify cashier + waiter so the bill is prepared without delay.
        // Customer requests run outside the admin BranchContext, so we pin
        // the session's branch when dispatching.
        \App\Support\BranchContext::forBranch($session->branch_id, function () use ($session) {
            app(\App\Services\NotifyService::class)
                ->billRequested($session->fresh()->load('table'));
        });

        return back()->with('success', 'وصل طلب الفاتورة للكاشير. سنجهزها لك بأقرب وقت.');
    }
}
