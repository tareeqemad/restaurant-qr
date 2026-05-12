<?php

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Support\BranchContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user('customer');

        // Customer's data spans every branch they've ever interacted with,
        // so we read unscoped — the customer is the global axis here.
        [$nextRes, $stats, $activeOrders, $recentOrders, $orderStats] =
            BranchContext::unscoped(function () use ($customer) {
                $next = Reservation::with('branch')
                    ->where('customer_id', $customer->id)
                    ->upcoming()
                    ->orderBy('reserved_for')
                    ->first();

                $stats = [
                    'upcoming'  => Reservation::where('customer_id', $customer->id)->upcoming()->count(),
                    'completed' => Reservation::where('customer_id', $customer->id)
                        ->withStatus(ReservationStatus::Completed)->count(),
                    'cancelled' => Reservation::where('customer_id', $customer->id)
                        ->withStatus(ReservationStatus::Cancelled)->count(),
                ];

                // Live orders (still in flight) — shown prominently so the
                // diner can track preparation. Recent finished orders are
                // shown as a compact list below for re-ordering reference.
                $activeOrders = Order::with('branch:id,name')
                    ->where('customer_id', $customer->id)
                    ->whereIn('status', OrderStatus::active())
                    ->latest('created_at')
                    ->limit(5)
                    ->get();

                $recentOrders = Order::with('branch:id,name')
                    ->where('customer_id', $customer->id)
                    ->whereNotIn('status', OrderStatus::active())
                    ->latest('created_at')
                    ->limit(5)
                    ->get();

                $orderStats = [
                    'active'    => Order::where('customer_id', $customer->id)
                        ->whereIn('status', OrderStatus::active())->count(),
                    'completed' => Order::where('customer_id', $customer->id)
                        ->whereIn('status', [OrderStatus::Completed->value, OrderStatus::Delivered->value])->count(),
                    'total'     => Order::where('customer_id', $customer->id)->count(),
                ];

                return [$next, $stats, $activeOrders, $recentOrders, $orderStats];
            });

        return view('portal.dashboard', [
            'customer'     => $customer,
            'nextRes'      => $nextRes,
            'stats'        => $stats,
            'activeOrders' => $activeOrders,
            'recentOrders' => $recentOrders,
            'orderStats'   => $orderStats,
        ]);
    }
}
