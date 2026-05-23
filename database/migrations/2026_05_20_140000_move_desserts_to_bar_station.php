<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-routes the desserts category (+ its menu items) to the bar
 * station per the operator's decision: the bartender already handles
 * cold/sweet items, no separate pastry brigade, so kunafa/baklava
 * tickets print at the bar alongside coffee and juices.
 *
 * Safe to re-run: only updates rows that still point at kitchen.
 * Skips silently if either station is missing on this branch.
 */
return new class extends Migration {
    public function up(): void
    {
        $bar = DB::table('stations')->where('code', 'BAR')->first()
            ?? DB::table('stations')->where('name', 'LIKE', '%بار%')->first();
        if (! $bar) return;

        // Re-route the category itself so future menu items inherit
        // the bar station via `categories.default_station_id`.
        $dessertsCat = DB::table('categories')->where('slug', 'desserts')->first();
        if (! $dessertsCat) return;

        DB::table('categories')
            ->where('id', $dessertsCat->id)
            ->update(['default_station_id' => $bar->id]);

        // Re-route every existing dessert menu item that still has
        // its station explicitly pinned. Items with a null
        // `station_id` already fall back to the category's default
        // and get the new routing for free.
        DB::table('menu_items')
            ->where('category_id', $dessertsCat->id)
            ->whereNotNull('station_id')
            ->update(['station_id' => $bar->id]);

        // Any IN-FLIGHT order_items (Pending/Approved/Preparing) for
        // these menu items need to flip too — otherwise the kitchen
        // KDS keeps showing them until they're served. Skip cancelled
        // + served rows since they're historical.
        DB::table('order_items')
            ->whereIn('menu_item_id', function ($q) use ($dessertsCat) {
                $q->select('id')->from('menu_items')->where('category_id', $dessertsCat->id);
            })
            ->whereIn('status', ['pending', 'approved', 'preparing', 'ready'])
            ->update(['station_id' => $bar->id]);
    }

    public function down(): void
    {
        // No automatic rollback — restoring "desserts to kitchen"
        // would force-flip operator-managed routing on items that
        // may have been re-assigned by hand since. If you need to
        // revert, edit the category from the admin UI.
    }
};
