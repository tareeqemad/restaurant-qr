<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Station;
use App\Services\OrderService;

/**
 * Station (kitchen / bar / grill / dessert / coffee / …) display controller.
 *
 * One generic `show($code)` replaces the old hardcoded `kitchen()` + `bar()`
 * methods, so adding a new station is a pure admin-panel action with zero
 * code changes. Access is gated by the per-station permission that
 * StationObserver maintains (`station.{code}.view`).
 */
class KitchenDisplayController extends Controller
{
    public function __construct(protected OrderService $service) {}

    public function show(string $code)
    {
        $station = Station::where('code', $code)->firstOrFail();

        abort_unless(auth()->user()->canAccessStation($station->code), 403);

        // No items query here — the Livewire kitchen-board component loads
        // (and live-refreshes) its own tickets. The old duplicate query was
        // dead weight the view never read.
        return view('admin.kitchen.display', compact('station'));
    }

    public function startItem(OrderItem $item)
    {
        abort_unless(auth()->user()->canAccessStation($item->station?->code ?? ''), 403);
        $this->service->startPreparing($item, auth()->id());
        return back()->with('success', 'تم بدء التحضير');
    }

    public function markReady(OrderItem $item)
    {
        abort_unless(auth()->user()->canAccessStation($item->station?->code ?? ''), 403);
        $this->service->markItemReady($item);
        return back()->with('success', 'الصنف جاهز');
    }
}
