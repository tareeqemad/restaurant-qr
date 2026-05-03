<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Support\BranchContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user('customer');

        // Customer's reservations span every branch they've ever booked at,
        // so we read unscoped — the customer is the global axis here.
        [$nextRes, $stats] = BranchContext::unscoped(function () use ($customer) {
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

            return [$next, $stats];
        });

        return view('portal.dashboard', [
            'customer' => $customer,
            'nextRes'  => $nextRes,
            'stats'    => $stats,
        ]);
    }
}
