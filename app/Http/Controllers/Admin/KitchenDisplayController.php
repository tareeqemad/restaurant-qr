<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;

/**
 * Station (kitchen / bar / grill / dessert / coffee / …) display controller.
 *
 * One generic `show($code)` replaces the old hardcoded `kitchen()` + `bar()`
 * methods, so adding a new station is a pure admin-panel action with zero
 * code changes. Access is gated by the per-station permission that
 * StationObserver maintains (`station.{code}.view`).
 *
 * Item transitions (start / ready / undo / cancel) live entirely in the
 * Livewire kitchen-board component — the legacy POST endpoints that used
 * to live here were unreferenced dead weight and were removed.
 */
class KitchenDisplayController extends Controller
{
    public function show(string $code)
    {
        $station = Station::where('code', $code)->firstOrFail();

        abort_unless(auth()->user()->canAccessStation($station->code), 403);

        // No items query here — the Livewire kitchen-board component loads
        // (and live-refreshes) its own tickets. The old duplicate query was
        // dead weight the view never read.
        return view('admin.kitchen.display', compact('station'));
    }
}
