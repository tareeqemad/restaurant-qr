<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function show(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $orders = $session->orders()->with('items.modifiers')->whereNotIn('status', ['cancelled'])->get();
        $totals = [
            'subtotal' => $orders->sum('subtotal'),
            'tax' => $orders->sum('tax_total'),
            'service' => $orders->sum('service_total'),
            'total' => $orders->sum('total'),
        ];
        return view('customer.bill', compact('orders', 'totals', 'session'));
    }
}
