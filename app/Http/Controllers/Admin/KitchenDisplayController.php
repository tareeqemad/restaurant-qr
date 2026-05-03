<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderItemStatus;
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

        $items = OrderItem::with(['order.table', 'modifiers'])
            ->where('station_id', $station->id)
            ->whereIn('status', [
                OrderItemStatus::Approved->value,
                OrderItemStatus::Preparing->value,
                OrderItemStatus::Ready->value,
            ])
            ->whereHas('order', fn($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
            ->orderBy('approved_at')
            ->get()
            ->groupBy('status');

        return view('admin.kitchen.display', compact('items', 'station'));
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
