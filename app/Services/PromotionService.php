<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves "what promotion applies to this menu item right now?" —
 * the only place where conflict resolution happens. Everything else
 * (cashier, customer menu, OrderService) calls this.
 *
 * Conflict order (when multiple promotions match the same item at
 * the same moment):
 *
 *   1. Highest `priority` wins.
 *   2. Tie-break by SPECIFICITY: a menu_item-targeted promo always
 *      beats a category-targeted one (admin's deliberate hand wins
 *      over a blanket category rule).
 *   3. Still tied? Pick the one that started LATEST — the assumption
 *      being that a newly-launched campaign supersedes an older one.
 *
 * Per-request caching is keyed by (item_id, branch_id, minute) — at
 * the same minute, two calls for the same item return the same row
 * without re-querying. That's safe because promos are date-precise
 * to the second but the menu/cashier UI doesn't refresh more often.
 */
class PromotionService
{
    /** @var array<string, MenuPromotion|null> */
    protected array $cache = [];

    /**
     * Find the single best active promotion for a menu item AT a moment
     * in time. Returns null when no promo applies (the item is at full
     * menu price).
     *
     * @param string|null $channel  Order source (qr/cashier/waiter/etc.)
     *                              used to filter promos that opted out
     *                              of certain channels. Null = no filter
     *                              (callers without channel context).
     * @param \App\Models\Customer|null $customer  Active customer (if any).
     *                              Used to gate birthday-month promos.
     *                              Guests fail any audience-restricted
     *                              promo silently.
     */
    public function resolveForItem(MenuItem $item, ?Carbon $when = null, ?int $branchId = null, ?string $channel = null, ?\App\Models\Customer $customer = null): ?MenuPromotion
    {
        $when     ??= now();
        $branchId ??= BranchContext::current();
        $cacheKey   = sprintf('%d:%s:%s:%s:%s', $item->id, $branchId ?? 'all', $channel ?? '_', $customer?->id ?? '_', $when->format('Y-m-d_H_i'));
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // Hot-path query: every active promotion that could match this
        // (item, branch) — the schedule filter still happens in PHP via
        // isLiveAt() because day-of-week + time-of-day logic isn't
        // portable across SQLite (used in tests) and MySQL.
        //
        // MenuPromotion does NOT use BelongsToBranch (chain-wide null
        // rows coexist with branch-specific rows) — so we write the
        // (whereNull OR =branchId) shape explicitly right here.
        $candidates = MenuPromotion::query()
            ->where('active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) $q->orWhere('branch_id', $branchId);
            })
            ->where(function ($q) use ($item) {
                $q->where(function ($q2) use ($item) {
                    $q2->where('target_type', MenuPromotion::TARGET_MENU_ITEM)
                       ->where('target_id', $item->id);
                })->orWhere(function ($q2) use ($item) {
                    $q2->where('target_type', MenuPromotion::TARGET_CATEGORY)
                       ->where('target_id', $item->category_id);
                });
            })
            ->get()
            ->filter(fn (MenuPromotion $p) => $p->isLiveAt($when))
            // Channel guard: drop promos that don't allow the order
            // source we're resolving for. Null channel = no opinion.
            ->filter(fn (MenuPromotion $p) => $p->allowsChannel($channel))
            // Audience guard: birthday-month promos only fire for the
            // matching customer. Guests are filtered out implicitly.
            ->filter(fn (MenuPromotion $p) => $p->matchesAudience($customer, $when))
            // Usage cap: a promo at its quota is treated as inactive.
            // The atomic increment lives in OrderService::addItem.
            ->filter(fn (MenuPromotion $p) => ! $p->isExhausted())
            // BXGY promos don't price the FIRST line — they discount
            // qualifying line-counts at the cart level after recalc.
            // Skip them in the per-item resolver entirely; the
            // applyBxgyToOrder hook below handles them.
            ->filter(fn (MenuPromotion $p) => ! $p->isBxgy())
            // Category-targeted promos may have item exclusions; the
            // category match was a DB-level shortcut, the exclusion
            // check has to happen in PHP since it's JSON-encoded.
            ->filter(fn (MenuPromotion $p) => $p->appliesToItem($item));

        if ($candidates->isEmpty()) {
            return $this->cache[$cacheKey] = null;
        }

        $winner = $candidates
            ->sortByDesc(fn (MenuPromotion $p) => [
                (int) $p->priority,
                $p->target_type === MenuPromotion::TARGET_MENU_ITEM ? 1 : 0,
                $p->starts_at?->timestamp ?? 0,
                $p->id,
            ])
            ->first();

        return $this->cache[$cacheKey] = $winner;
    }

    /**
     * Compute the effective price for a menu item right now. Convenience
     * for the hot-path callers (cashier POS, customer menu) that only
     * need a number, not the promo object itself.
     */
    public function effectivePriceFor(MenuItem $item, ?Carbon $when = null, ?int $branchId = null, ?string $channel = null): float
    {
        $promo = $this->resolveForItem($item, $when, $branchId, $channel);
        return $promo ? $promo->applyTo((float) $item->price) : (float) $item->price;
    }

    /**
     * Bulk variant for the menu screen: takes a collection of items and
     * returns a map [item_id => ['promotion' => ?MenuPromotion, 'price' => float]].
     * One query covers every category + item, eliminating the N+1 risk
     * when rendering 80+ menu items at once.
     *
     * Same channel filter as resolveForItem — when the customer menu is
     * rendered for a QR session, pass channel='qr' so a cashier-only
     * promo doesn't accidentally advertise itself to the diner.
     */
    public function resolveBulk(Collection $items, ?Carbon $when = null, ?int $branchId = null, ?string $channel = null): array
    {
        if ($items->isEmpty()) return [];

        $when     ??= now();
        $branchId ??= BranchContext::current();

        $itemIds = $items->pluck('id')->all();
        $categoryIds = $items->pluck('category_id')->filter()->unique()->all();

        // Pull every potentially-matching promo in ONE query.
        $promos = MenuPromotion::query()
            ->where('active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) $q->orWhere('branch_id', $branchId);
            })
            ->where(function ($q) use ($itemIds, $categoryIds) {
                $q->where(function ($q2) use ($itemIds) {
                    $q2->where('target_type', MenuPromotion::TARGET_MENU_ITEM)
                       ->whereIn('target_id', $itemIds);
                });
                if (! empty($categoryIds)) {
                    $q->orWhere(function ($q2) use ($categoryIds) {
                        $q2->where('target_type', MenuPromotion::TARGET_CATEGORY)
                           ->whereIn('target_id', $categoryIds);
                    });
                }
            })
            ->get()
            ->filter(fn (MenuPromotion $p) => $p->isLiveAt($when))
            ->filter(fn (MenuPromotion $p) => $p->allowsChannel($channel));

        $out = [];
        foreach ($items as $item) {
            $matches = $promos->filter(fn (MenuPromotion $p) => $p->appliesToItem($item));

            if ($matches->isEmpty()) {
                $out[$item->id] = ['promotion' => null, 'price' => (float) $item->price];
                continue;
            }

            $winner = $matches->sortByDesc(fn (MenuPromotion $p) => [
                (int) $p->priority,
                $p->target_type === MenuPromotion::TARGET_MENU_ITEM ? 1 : 0,
                $p->starts_at?->timestamp ?? 0,
                $p->id,
            ])->first();

            $out[$item->id] = [
                'promotion' => $winner,
                'price'     => $winner->applyTo((float) $item->price),
            ];
        }
        return $out;
    }

    /**
     * Apply Buy-N-Get-M promos at the order level — these are excluded
     * from the per-item resolver because they discount a SET of lines,
     * not a single price. Called from OrderService::recalculateTotals
     * after `subtotal` is known.
     *
     * Algorithm (per applicable BXGY promo):
     *   1. Find all non-cancelled order_items targeting this promo's
     *      menu_item or category.
     *   2. Sum their effective `quantity` (handles "5 of the same
     *      burger" and "3 desserts across 3 lines" alike).
     *   3. For every `buy_qty + get_qty` block of items, mark `get_qty`
     *      items as free — taking the CHEAPEST first so the customer
     *      gets the best deal (industry standard).
     *   4. Record the total as a single `OrderDiscount` row using the
     *      promotion's name so reports + invoices show it.
     *
     * Idempotent: any existing BXGY-tagged OrderDiscount for the order
     * is removed first, then recomputed. Safe to call after any cart
     * mutation (add, remove, qty change).
     */
    public function applyBxgyToOrder(\App\Models\Order $order): void
    {
        // Clear stale BXGY discounts so we recompute cleanly.
        $order->discounts()->where('reason', 'like', 'BXGY:%')->delete();

        $now = now();
        $branchId = $order->branch_id;

        $bxgyPromos = MenuPromotion::query()
            ->where('active', true)
            ->whereNotNull('bxgy_buy_qty')
            ->whereNotNull('bxgy_get_qty')
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id');
                if ($branchId) $q->orWhere('branch_id', $branchId);
            })
            ->where(function ($q) {
                $q->whereNull('channels');
                // channel filter handled in PHP below — JSON contains is
                // unwieldy across MySQL/SQLite.
            })
            ->get()
            ->filter(fn (MenuPromotion $p) => $p->isLiveAt($now))
            ->filter(fn (MenuPromotion $p) => $p->allowsChannel($order->order_source));

        if ($bxgyPromos->isEmpty()) return;

        $items = $order->items()
            ->where('status', '!=', \App\Enums\OrderItemStatus::Cancelled->value)
            ->with('menuItem')
            ->get();

        foreach ($bxgyPromos as $promo) {
            // Eligible lines for this promo (item or category target).
            $eligible = $items->filter(fn ($i) => $i->menuItem && $promo->appliesToItem($i->menuItem));
            if ($eligible->isEmpty()) continue;

            // Expand each line into per-piece (qty=1) tuples so we can
            // pick "the cheapest N" precisely.
            $pieces = collect();
            foreach ($eligible as $line) {
                for ($i = 0; $i < (int) $line->quantity; $i++) {
                    $pieces->push([
                        'unit_price' => (float) $line->unit_price + (float) $line->modifiers_total,
                        'line_id'    => $line->id,
                    ]);
                }
            }
            $buyQty = (int) $promo->bxgy_buy_qty;
            $getQty = (int) $promo->bxgy_get_qty;
            $blockSize = $buyQty + $getQty;
            if ($blockSize <= 0) continue;

            $blockCount = intdiv($pieces->count(), $blockSize);
            if ($blockCount === 0) continue;

            // Number of free pieces total + their value (cheapest-first).
            $freePieceCount = $blockCount * $getQty;
            $cheapest = $pieces->sortBy('unit_price')->take($freePieceCount);
            $savings = round((float) $cheapest->sum('unit_price'), 2);
            if ($savings <= 0) continue;

            $order->discounts()->create([
                'name_snapshot' => "{$promo->name} (اشترِ {$buyQty} خذ {$getQty})",
                'type'          => 'fixed',
                'value'         => $savings,
                'amount'        => $savings,
                'reason'        => "BXGY:{$promo->name} (اشترِ {$buyQty} خذ {$getQty})",
                'applied_by_user_id' => $order->created_by_user_id,
            ]);
        }
    }

    /** Resets the per-request cache. Tests use this between assertions. */
    public function flush(): void
    {
        $this->cache = [];
    }
}
