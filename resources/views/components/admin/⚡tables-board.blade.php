<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Lookup;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\OrderService;
use App\Support\Duration;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tables board v3 — the waiter's TRIAGE board, not a wall of table cards.
 *
 * The old board showed 40 equal cards in number order, so a waiter had to
 * scan the whole floor to find the 3 tables that actually needed them. This
 * version leads with a short, urgency-sorted "what needs me now" feed (always
 * 3-8 items no matter how big the floor) and keeps a compact, section-grouped
 * floor map underneath for orientation and starting new tables. Defaults to
 * "my tables" (assigned_waiter_id) for waiters so nobody drowns in the full 40.
 *
 * Refreshes EVENT-DRIVEN on the private `waiters` channel (order/table/item
 * events), with a light visible poll fallback for shared hosting.
 */
new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * ONE control, not two. An earlier cut had a mine/all toggle AND a section
     * filter — two overlapping axes for a waiter to reason about mid-rush, and
     * once the roster exists "my tables" and "my section" are the same set
     * anyway. So the whole navigation is a single tab strip:
     *
     *   'mine'      → my rostered section(s), or claim-based ownership if the
     *                 floor has no roster today (see applyMineScope)
     *   'all'       → the whole floor
     *   "<zoneId>"  → one specific section
     *
     * '' resolves to a role default in mount(): waiters open on their own
     * section, managers on the whole floor.
     */
    #[Url(as: 'view', except: '')]
    public string $view = '';

    /**
     * '' = the feed shows what a guest is waiting on. 'stale' = show the
     * housekeeping pile instead. Only ONE lens exists on purpose: available and
     * occupied are already the map's whole job, and everything actionable is
     * already IN the feed — so a row of five filter chips would be decoration
     * competing with the strip for the one screen a waiter actually reads.
     */
    #[Url(as: 'lens', except: '')]
    public string $lens = '';

    public function mount(): void
    {
        if ($this->view === '') {
            // "قسمي" only means anything if someone rostered me. Without a
            // roster the system genuinely does not know which tables are mine,
            // and the claim-based fallback resolves to ~the whole floor anyway
            // (36 of 40 on a real 40-table floor) — so defaulting an unrostered
            // waiter there hands them a tab labelled "قسمي 36" that is simply a
            // lie, and leaves them exactly as drowned as before. Open honestly
            // on the whole floor instead, and tell them they're unrostered.
            $this->view = ($this->isWaiter() && $this->myZoneIds() !== []) ? 'mine' : 'all';
        }
    }

    protected function viewingSectionId(): ?int
    {
        return ctype_digit($this->view) ? (int) $this->view : null;
    }

    protected function isWaiter(): bool
    {
        return auth()->user()?->role === UserRole::Waiter->value;
    }

    /**
     * Minutes a guest can sit with an open session and no order before the
     * board nags the waiter to take their order. Reuses the same "attention"
     * knob the long-session/idle-close rules use, halved (a table waiting to
     * order is more urgent than one lingering after eating).
     */
    protected function seatedNoOrderMinutes(): int
    {
        return max(3, (int) round($this->attentionMinutes() / 15));
    }

    protected function attentionMinutes(): int
    {
        return (int) config('restaurant.order.session_attention_minutes', 75);
    }

    /**
     * One row per table with everything the feed AND the map need, computed
     * once. `triage` is the single most-urgent actionable reason (or null).
     */
    #[Computed]
    public function rows()
    {
        $q = Table::with([
                'activeSession' => fn ($s) => $s->withCount([
                    'orders as all_orders_count',
                    'orders as cancelled_orders_count' => fn ($o) => $o
                        ->where('status', OrderStatus::Cancelled->value),
                ]),
                'activeSession.assignedWaiter:id,name',
                'activeSession.invoice',
                'activeSession.orders' => fn ($orders) => $orders
                    ->whereIn('status', OrderStatus::active())
                    ->latest('updated_at'),
                'zone',
            ])->orderBy('number');

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('number', 'like', "%{$s}%")
                   ->orWhere('name', 'like', "%{$s}%")
                   ->orWhereHas('zone', fn ($z) => $z->where('label', 'like', "%{$s}%"));
            });
        }

        if ($sectionId = $this->viewingSectionId()) {
            $q->where('zone_lookup_id', $sectionId);
        } elseif ($this->view === 'mine') {
            $this->applyMineScope($q);
        }

        $attention = $this->attentionMinutes();
        $seatedMin = $this->seatedNoOrderMinutes();

        return $q->get()->map(fn (Table $t) => $this->buildRow($t, $attention, $seatedMin));
    }

    /** Sections I'm rostered on today (current branch). Empty = nobody assigned me. */
    protected ?array $myZonesMemo = null;

    protected function myZoneIds(): array
    {
        return $this->myZonesMemo ??= SectionAssignment::zoneIdsFor((int) auth()->id());
    }

    /**
     * "My tables", in order of authority:
     *
     * 1. PLANNED — if a manager rostered me onto sections today, those sections
     *    are mine, free tables included: I seat and serve my own section. Plus
     *    any table elsewhere where I'm the one actually serving the party.
     * 2. FALLBACK — no roster set for today, so fall back to claim-based
     *    ownership: unclaimed sessions + free tables + parties I claimed. A
     *    restaurant that never opens the assignment screen keeps working
     *    exactly as it did before this existed.
     *
     * Unclaimed (assigned_waiter_id NULL) is load-bearing in the fallback: a
     * customer QR session is created with no waiter and only gets one when
     * someone APPROVES an order. Excluding NULL deadlocked the core flow — the
     * pending order was invisible to every waiter, so nobody could approve it
     * to claim it.
     */
    protected function applyMineScope($q): void
    {
        $me = (int) auth()->id();
        $zoneIds = $this->myZoneIds();

        if ($zoneIds !== []) {
            $q->where(function ($w) use ($zoneIds, $me) {
                $w->whereIn('zone_lookup_id', $zoneIds)
                  ->orWhereHas('activeSession', fn ($s) => $s->where('assigned_waiter_id', $me));
            });

            return;
        }

        $q->where(function ($w) use ($me) {
            $w->whereDoesntHave('activeSession')
              ->orWhereHas('activeSession', fn ($s) => $s->where(
                  // Nested closure REQUIRED: a flat where()->orWhereNull() would
                  // OR out of the relation's own status='active' constraint and
                  // match closed sessions too.
                  fn ($x) => $x->where('assigned_waiter_id', $me)->orWhereNull('assigned_waiter_id')
              ));
        });
    }

    protected function buildRow(Table $t, int $attention, int $seatedMin): array
    {
        $session      = $t->activeSession;
        $orders       = $session?->orders ?? collect();       // active statuses only
        $activeCount  = $orders->count();
        $pendingCount = $orders->where('status', OrderStatus::Pending->value)->count();
        $readyCount   = $orders->where('status', OrderStatus::Ready->value)->count();
        $openMinutes  = $session?->opened_at ? (int) abs($session->opened_at->diffInMinutes(now())) : 0;
        $lastSeenAt   = $session?->last_activity_at ?? $session?->opened_at;
        $idleMinutes  = $lastSeenAt ? (int) abs($lastSeenAt->diffInMinutes(now())) : null;
        $invoice      = $session?->invoice;
        $billRequested = filled($session?->bill_requested_at);

        // Idle-close eligibility — mirrors TableController@closeSession: sat idle
        // past the threshold AND zero financial exposure (no orders, all
        // cancelled, or the invoice fully settled).
        $allOrdersCount   = (int) ($session?->all_orders_count ?? 0);
        $cancelledCount   = (int) ($session?->cancelled_orders_count ?? 0);
        $invoiceSettled   = $invoice && $invoice->status !== 'cancelled' && (float) $invoice->balance <= 0;
        $zeroExposure     = $allOrdersCount === 0 || $allOrdersCount === $cancelledCount || $invoiceSettled;
        $canCloseIdle     = $session && $idleMinutes !== null && $idleMinutes >= $attention && $zeroExposure;

        $perms = $this->branchPerms($t);

        // ── The single most-urgent actionable reason (or none) ──────────────
        $helpRequested = filled($session?->help_requested_at);

        $triage = null;
        if ($session) {
            if ($helpRequested) {
                // Outranks even cold food: a guest has their hand up RIGHT NOW
                // and is watching the room to see if anyone comes. Nothing on
                // this board is more time-critical than that.
                $triage = [
                    'type' => 'help', 'rank' => 6, 'urgency' => 'red',
                    'icon' => 'bi-hand-index-thumb-fill',
                    'label' => trim('الزبون طلب مساعدة'.($session->help_request_note ? ' — '.$session->help_request_note : '')),
                    'since' => $session->help_requested_at,
                    'action' => ['help_ack' => true],
                ];
            } elseif ($readyCount > 0) {
                // ready_at, NOT updated_at: this is the cold-food clock — the
                // whole reason the tier is red. updated_at is bumped by ANY
                // later write to the order row (another line changing status, a
                // total recompute), which would silently reset the clock and
                // make food that's been sitting 20 minutes look fresh.
                $since = $orders->where('status', OrderStatus::Ready->value)
                    ->pluck('ready_at')->filter()->min() ?? $session->opened_at;
                $triage = [
                    'type' => 'food_ready', 'rank' => 5, 'urgency' => 'red',
                    'icon' => 'bi-bell-fill',
                    'label' => $readyCount.' جاهز للتسليم — سلّمه قبل ما يبرد',
                    'since' => $since,
                    // Serve in place. A cashier can see this board but can't
                    // serve, so they get the (table-filtered) list instead of a
                    // button that would 403 in their hand mid-rush.
                    'action' => $this->canServe()
                        ? ['serve' => true]
                        : ['url' => route('admin.orders.index', ['table_id' => $t->id]), 'label' => 'شوف الطلب', 'icon' => 'bi-list-check'],
                ];
            } elseif ($billRequested && ! $invoiceSettled) {
                $triage = [
                    'type' => 'bill', 'rank' => 4, 'urgency' => 'bill',
                    'icon' => 'bi-receipt-cutoff',
                    'label' => 'طلبت الفاتورة',
                    'since' => $session->bill_requested_at,
                    'action' => ['url' => route('admin.cashier.show', $session), 'label' => 'كاشير', 'icon' => 'bi-cash-stack'],
                ];
            } elseif ($pendingCount > 0) {
                $since = $orders->where('status', OrderStatus::Pending->value)
                    ->pluck('created_at')->filter()->min() ?? $session->opened_at;
                $triage = [
                    'type' => 'approval', 'rank' => 3, 'urgency' => 'amber',
                    'icon' => 'bi-person-check',
                    'label' => $pendingCount.' بانتظار الاعتماد',
                    'since' => $since,
                    // table_id: OrderController@index has always accepted this
                    // filter — the card just never passed it, so "approve table
                    // 12" landed on every order in the branch.
                    'action' => ['url' => route('admin.orders.index', ['table_id' => $t->id]), 'label' => 'اعتمد', 'icon' => 'bi-check-lg'],
                ];
            } elseif ($canCloseIdle) {
                // MUST precede no_order: an abandoned QR scan has zero orders,
                // so no_order's condition (activeCount===0 && open>=5min) is a
                // permanent superset that would mask this branch forever and
                // make the close-session form unreachable for the exact case it
                // exists for. A table seated 5-75min with no order still gets
                // "خُد الطلب" — only once it's idle past the attention
                // threshold with zero exposure does it flip to "أغلق الراكدة".
                $triage = [
                    'type' => 'idle', 'rank' => 1, 'urgency' => 'grey',
                    'icon' => 'bi-hourglass-bottom',
                    'label' => 'جلسة راكدة بلا مستحقات',
                    'since' => $lastSeenAt,
                    // Closing authorizes 'update' (admin|manager only), so a
                    // waiter would hit a 403 dead end — this is their card's
                    // ONLY action. Give them something they can actually do.
                    'action' => $perms['update']
                        ? ['close' => true]
                        : ['url' => route('admin.waiter-orders.create', $t), 'label' => 'تفقّد', 'icon' => 'bi-eye'],
                ];
            } elseif ($activeCount === 0 && $openMinutes >= $seatedMin) {
                $triage = [
                    'type' => 'no_order', 'rank' => 2, 'urgency' => 'amber',
                    'icon' => 'bi-clipboard-x',
                    'label' => 'قاعدين بلا طلب',
                    'since' => $session->opened_at,
                    'action' => ['url' => route('admin.waiter-orders.create', $t), 'label' => 'خُد الطلب', 'icon' => 'bi-clipboard-plus'],
                ];
            } elseif ($openMinutes >= $attention) {
                $triage = [
                    'type' => 'long', 'rank' => 1, 'urgency' => 'grey',
                    'icon' => 'bi-hourglass-split',
                    'label' => 'جلسة طويلة تحتاج متابعة',
                    'since' => $session->opened_at,
                    'action' => ['url' => route('admin.waiter-orders.create', $t), 'label' => 'تفقّد', 'icon' => 'bi-eye'],
                ];
            }
        } elseif ($t->needsCleaning()) {
            // No party sitting here — the last one left a table nobody wiped.
            // Ranks below anything involving a live guest: a dirty table costs
            // you the next seating, a cold plate costs you this one.
            $triage = [
                'type' => 'cleaning', 'rank' => 2, 'urgency' => 'amber',
                'icon' => 'bi-stars',
                'label' => 'تحتاج تنظيف',
                'since' => $t->needs_cleaning_since,
                'action' => ['clean' => true],
            ];
        }

        // Every table in the feed MUST be marked in the map — otherwise a table
        // the feed is shouting about looks like a calm "مشغولة" tile, and the
        // two halves of the board disagree. 'grey' triage gets its own state.
        $tileState = match (true) {
            $triage && $triage['type'] === 'cleaning' => 'cleaning',
            $triage && $triage['urgency'] === 'red'   => 'urgent',
            $triage && $triage['urgency'] === 'bill'  => 'bill',
            $triage && $triage['urgency'] === 'amber' => 'attention',
            $triage && $triage['urgency'] === 'grey'  => 'stale',
            (bool) $session                            => 'occupied',
            $t->status === 'reserved'                  => 'reserved',
            $t->status === 'out_of_service'            => 'oos',
            default                                    => 'available',
        };

        return [
            'table'       => $t,
            'session'     => $session,
            'invoice'     => $invoice,
            'active_count' => $activeCount,
            'open_minutes' => $openMinutes,
            'last_seen_at' => $lastSeenAt,
            'waiter_name' => $session?->assignedWaiter?->name,
            'zone_id'     => $t->zone_lookup_id,
            'zone_label'  => $t->zone?->label,
            'zone_color'  => $t->zone?->color ?? '#94a3b8',
            'triage'      => $triage,
            'tile_state'  => $tileState,
            'perms'       => $perms,
        ];
    }

    /**
     * Housekeeping (a long-running or idle session) is NOT urgency.
     *
     * On a real floor these are day-old zombie sessions nobody closed, and they
     * buried the feed 4-to-1 under a single actual action — reproducing the
     * exact wall of noise this board exists to kill. They're still worth
     * clearing, so they get a lens of their own instead of the hero strip.
     */
    protected function isHousekeeping(array $row): bool
    {
        return ($row['triage']['urgency'] ?? null) === 'grey';
    }

    #[Computed]
    public function staleCount(): int
    {
        return $this->rows->filter(fn ($r) => $r['triage'] && $this->isHousekeeping($r))->count();
    }

    /** The feed: actionable tables only, most-urgent first, longest-waiting first within a tier. */
    #[Computed]
    public function triage()
    {
        return $this->rows
            ->filter(fn ($r) => $r['triage'] !== null)
            // The lens flips the feed to housekeeping — the ONLY tier that has
            // to be asked for, because it's the only one no guest is waiting on.
            ->filter(fn ($r) => $this->lens === 'stale'
                ? $this->isHousekeeping($r)
                : ! $this->isHousekeeping($r))
            ->sort(function ($a, $b) {
                $rank = $b['triage']['rank'] <=> $a['triage']['rank'];
                if ($rank !== 0) {
                    return $rank;
                }
                $at = $a['triage']['since']?->timestamp ?? PHP_INT_MAX;
                $bt = $b['triage']['since']?->timestamp ?? PHP_INT_MAX;
                return $at <=> $bt; // oldest waiting first
            })
            ->values();
    }

    /** The map: every table in view, grouped by section (zone), for orientation. */
    #[Computed]
    public function floorSections()
    {
        return $this->rows
            ->groupBy(fn ($r) => $r['zone_label'] ?? 'بدون قسم')
            ->map(fn ($rows) => $rows->values());
    }

    /** Section filter chips — every zone with its table count (unfiltered). */
    #[Computed]
    public function sections()
    {
        $counts = Table::query()
            ->whereNotNull('zone_lookup_id')
            ->selectRaw('zone_lookup_id, COUNT(*) as cnt')
            ->groupBy('zone_lookup_id')
            ->pluck('cnt', 'zone_lookup_id');

        return Lookup::for('zones')->map(function ($z) use ($counts) {
            $z->tables_count = (int) ($counts[$z->id] ?? 0);
            return $z;
        });
    }

    /** Mirrors applyMineScope() exactly so the toggle's badge can't lie. */
    #[Computed]
    public function mineCount(): int
    {
        $q = Table::query();
        $this->applyMineScope($q);

        return $q->count();
    }

    /**
     * Table gates resolved ONCE PER BRANCH, not per table per gate.
     *
     * TablePolicy::update/transfer/delete are (role + inUserBranch) only — no
     * per-table state — and inUserBranch → User::belongsToBranch runs a FRESH
     * un-memoized `branches()->exists()` query for any non-owner user. Owners
     * short-circuit to true, which is exactly why testing this board as an
     * admin hid the storm: for a WAITER (the board's actual audience) the ⋯
     * menu's 3 gates × ~40 tiles × 2 render sites was ~240 queries a render.
     * Memoised per branch_id so all-branches mode stays correct.
     */
    protected array $permMemo = [];

    protected function branchPerms(Table $t): array
    {
        $branchId = (int) $t->branch_id;

        return $this->permMemo[$branchId] ??= [
            'update'   => Gate::allows('update', $t),
            'transfer' => Gate::allows('transfer', $t),
            'delete'   => Gate::allows('delete', $t),
        ];
    }

    #[Computed]
    public function allCount(): int
    {
        return Table::count();
    }

    /** Free tables anyone can transfer a session onto. */
    #[Computed]
    public function availableTables()
    {
        return Table::where('active', true)
            ->where('status', 'available')
            ->whereDoesntHave('activeSession')
            ->orderBy('number')
            ->get(['id', 'number', 'name']);
    }

    /**
     * Can this user hand food to a guest at all? OrderPolicy::serve is
     * admin|manager|waiter — but TablePolicy::viewAny also lets a CASHIER onto
     * this board, and they must not be shown a button that 403s. Role-only
     * check: no query, and serveTable() re-authorizes the real policy anyway.
     */
    protected ?bool $canServeMemo = null;

    protected function canServe(): bool
    {
        return $this->canServeMemo ??= (bool) auth()->user()?->hasAnyRole(['admin', 'manager', 'waiter']);
    }

    /**
     * "Delivered." Marks the table's ready food served, from the floor board.
     *
     * This is the waiter's most frequent and most time-critical action, and it
     * was the ONLY one in their loop that threw them off the board — into the
     * branch-wide order list, with no table filter, to hunt for table 12 by
     * hand. That is the exact "I can't keep up" this whole redesign set out to
     * kill, hiding in the one action that matters most.
     *
     * Mirrors the KDS's serveOrder(): per-order `serve` authorization, then
     * mark each READY line served. Items still cooking are left alone — a
     * half-ready ticket must not be signed off as delivered.
     */
    public function serveTable(int $sessionId, OrderService $orders): void
    {
        $session = TableSession::with('table')->find($sessionId);

        if (! $session || ! $session->table) {
            return;
        }

        abort_unless(auth()->user()?->can('view', $session->table), 403);

        try {
            $readyOrders = $session->orders()
                ->where('status', OrderStatus::Ready->value)
                ->with('items')
                ->get();

            foreach ($readyOrders as $order) {
                abort_unless(auth()->user()->can('serve', $order), 403);

                foreach ($order->items->where('status', OrderItemStatus::Ready->value) as $item) {
                    $orders->markItemServed($item, (int) auth()->id());
                }
            }

            $this->dispatch('toast', type: 'success', message: "تم تسليم طاولة {$session->table->number}.");
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // A stale tap: the line moved on another screen between polls. Show
            // the domain message instead of Livewire's full-screen error.
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());
        } finally {
            $this->refreshFromBroadcast();
        }
    }

    /**
     * "I went." Clears the guest's raised hand and records who answered.
     *
     * Scoped through TableSession's branch scope + an explicit table `view`
     * gate, so a forged session id from another branch can't be acked.
     */
    public function ackHelp(int $sessionId): void
    {
        $session = TableSession::with('table')->find($sessionId);

        if (! $session || ! $session->help_requested_at) {
            return;
        }

        abort_unless($session->table && auth()->user()?->can('view', $session->table), 403);

        $session->update([
            'help_requested_at' => null,
            'help_request_note' => null,
            'help_ack_by_user_id' => auth()->id(),
        ]);

        $this->refreshFromBroadcast();
    }

    public function setView(string $v): void
    {
        $this->view = ($v === 'mine' || $v === 'all' || ctype_digit($v)) ? $v : 'all';
    }

    public function toggleLens(string $l): void
    {
        $this->lens = ($this->lens === $l || $l !== 'stale') ? '' : $l;
    }

    /** My rostered sections — drives the "قسمي" tab's sub-label. */
    #[Computed]
    public function myZoneLabels(): array
    {
        $ids = $this->myZoneIds();

        return $ids === []
            ? []
            : Lookup::whereIn('id', $ids)->pluck('label')->all();
    }

    /** No roster, no "قسمي" tab — see mount(). */
    #[Computed]
    public function showsMineTab(): bool
    {
        return $this->myZoneIds() !== [];
    }

    /** Template-visible copy of the roster — the tab strip needs it to dedupe. */
    #[Computed]
    public function myZoneIdList(): array
    {
        return $this->myZoneIds();
    }

    /** A waiter nobody rostered today: say so instead of faking a section. */
    #[Computed]
    public function needsRosterNudge(): bool
    {
        return $this->isWaiter() && $this->myZoneIds() === [];
    }

    public function clearFilters(): void
    {
        $this->reset(['search']);
    }

    #[On('echo-private:waiters,.table.status_changed')]
    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.order.status_changed')]
    #[On('echo-private:waiters,.item.status_changed')]
    // The bill tier keys off the invoice balance, so without this a paid-but-
    // still-open session keeps shouting "طلبت الفاتورة" until the 15s poll and
    // the waiter chases a bill the cashier already settled.
    #[On('echo-private:waiters,.invoice.paid')]
    public function refreshFromBroadcast(): void
    {
        unset($this->rows, $this->triage, $this->floorSections, $this->sections,
              $this->mineCount, $this->allCount, $this->availableTables,
              $this->myZoneLabels, $this->showsMineTab, $this->needsRosterNudge,
              $this->staleCount, $this->myZoneIdList);
    }
}
?>

<div class="tables-board" wire:poll.visible.15s="refreshFromBroadcast">
    @php
        $triage = $this->triage;
        $sections = $this->floorSections;
        $availableTransferTables = $this->availableTables;
        $needAction = $triage->count();

        $tileMeta = [
            'urgent'    => ['label' => 'عاجل',        'icon' => 'bi-exclamation-triangle-fill'],
            'bill'      => ['label' => 'فاتورة',      'icon' => 'bi-receipt-cutoff'],
            'attention' => ['label' => 'تحتاج متابعة', 'icon' => 'bi-clock-history'],
            'cleaning'  => ['label' => 'تحتاج تنظيف', 'icon' => 'bi-stars'],
            'stale'     => ['label' => 'راكدة',       'icon' => 'bi-hourglass-bottom'],
            'occupied'  => ['label' => 'مشغولة',      'icon' => 'bi-people-fill'],
            'reserved'  => ['label' => 'محجوزة',      'icon' => 'bi-bookmark-check-fill'],
            'oos'       => ['label' => 'خارج الخدمة', 'icon' => 'bi-tools'],
            'available' => ['label' => 'متاحة',       'icon' => 'bi-check2-circle'],
        ];
    @endphp

    {{-- ── Section tabs: the ONE navigation control ────────────────────────── --}}
    <div class="tb-tabs" role="tablist" aria-label="أقسام الصالة">
        @if($this->showsMineTab)
            <button type="button" role="tab" wire:click="setView('mine')"
                aria-selected="{{ $view === 'mine' ? 'true' : 'false' }}"
                class="tb-tab tb-tab--mine {{ $view === 'mine' ? 'is-active' : '' }}">
                <i class="bi bi-person-badge"></i>
                <span class="tb-tab-label">
                    قسمي
                    <small>{{ implode(' · ', $this->myZoneLabels) }}</small>
                </span>
                <span class="tb-tab-count">{{ $this->mineCount }}</span>
            </button>
        @endif

        @foreach($this->sections as $z)
            {{-- Skip the section that IS "قسمي". A waiter rostered to one
                 section was handed two tabs showing the identical 15 tables —
                 "قسمي · داخلي" and "داخلي" — which is just a choice with no
                 difference, burned into the scarcest space on his screen. --}}
            @continue($this->showsMineTab && $this->myZoneIdList === [(int) $z->id])
            <button type="button" role="tab" wire:click="setView('{{ $z->id }}')"
                aria-selected="{{ $view === (string) $z->id ? 'true' : 'false' }}"
                class="tb-tab {{ $view === (string) $z->id ? 'is-active' : '' }}"
                style="--sec-color: {{ $z->color }};">
                <span class="tb-tab-dot"></span>
                <span class="tb-tab-label">{{ $z->label }}</span>
                <span class="tb-tab-count">{{ $z->tables_count }}</span>
            </button>
        @endforeach

        <button type="button" role="tab" wire:click="setView('all')"
            aria-selected="{{ $view === 'all' ? 'true' : 'false' }}"
            class="tb-tab {{ $view === 'all' ? 'is-active' : '' }}">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            <span class="tb-tab-label">كل الصالة</span>
            <span class="tb-tab-count">{{ $this->allCount }}</span>
        </button>

        @can('viewAny', \App\Models\Lookup::class)
            <a href="{{ route('admin.section-assignments.index') }}" class="tb-tabs-manage" title="توزيع الأقسام">
                <i class="bi bi-people-fill"></i>
                التوزيع
            </a>
        @endcan
    </div>

    @if($this->needsRosterNudge)
        <div class="tb-nudge">
            <i class="bi bi-person-x-fill"></i>
            <span>ما حدا وزّعك على قسم اليوم — فعم تشوف كل الصالة. اطلب من المدير يوزّعك عشان تشوف طاولاتك بس.</span>
        </div>
    @endif

    <div class="tb-command">
        <div class="tb-load {{ $needAction > 0 ? 'is-hot' : '' }}">
            @if($needAction > 0)
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>{{ $needAction }} يحتاجون إجراء الآن</span>
            @else
                <i class="bi bi-check2-circle"></i>
                <span>الصالة تحت السيطرة</span>
            @endif
        </div>

        <div class="tb-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" wire:model.live.debounce.400ms="search"
                placeholder="رقم الطاولة أو القسم" class="tb-search">
            @if($search)
                <button type="button" wire:click="$set('search','')" class="tb-search-clear" title="مسح"><i class="bi bi-x-lg"></i></button>
            @endif
        </div>
    </div>

    {{-- ── Priority feed: the hero. Short, urgency-sorted, one action each. ── --}}
    <section class="tb-feed" aria-label="شريط المتابعة">
        <header class="tb-feed-head">
            <i class="bi {{ $lens === 'stale' ? 'bi-hourglass-bottom' : 'bi-lightning-charge-fill' }}"></i>
            <h3>{{ $lens === 'stale' ? 'الجلسات الراكدة' : 'شريط المتابعة' }}</h3>
            <span class="tb-feed-sub">{{ $lens === 'stale' ? 'تدبير — ما حدا مستني عليها' : 'مرتّب حسب الإلحاح' }}</span>

            {{-- The one lens. Housekeeping is the only tier kept OUT of the feed
                 (no guest is waiting on it), so it's the only one that needs a
                 way back in. --}}
            @if($this->staleCount > 0 || $lens === 'stale')
                <button type="button" wire:click="toggleLens('stale')"
                    class="tb-lens {{ $lens === 'stale' ? 'is-active' : '' }}"
                    aria-pressed="{{ $lens === 'stale' ? 'true' : 'false' }}">
                    <i class="bi {{ $lens === 'stale' ? 'bi-arrow-right' : 'bi-hourglass-bottom' }}"></i>
                    {{ $lens === 'stale' ? 'رجوع للمتابعة' : 'راكدة' }}
                    @if($lens !== 'stale')<span class="tb-lens-count">{{ $this->staleCount }}</span>@endif
                </button>
            @endif
        </header>

        @forelse($triage as $r)
            @php
                $t = $r['table'];
                $g = $r['triage'];
                $since = $g['since'] ? Duration::since($g['since']) : null;
            @endphp
            <article class="tb-feed-card tb-urg-{{ $g['urgency'] }}" wire:key="feed-{{ $t->id }}">
                <div class="tb-feed-num">
                    <small>طاولة</small>
                    <strong>{{ $t->number }}</strong>
                </div>
                <div class="tb-feed-body">
                    <div class="tb-feed-label"><i class="bi {{ $g['icon'] }}"></i> {{ $g['label'] }}</div>
                    <div class="tb-feed-meta">
                        @if($since)<span><i class="bi bi-clock"></i> {{ $since }}</span>@endif
                        @if($r['zone_label'])<span class="tb-feed-zone"><span class="tb-zdot" style="--sec-color: {{ $r['zone_color'] }};"></span>{{ $r['zone_label'] }}</span>@endif
                        @if($r['waiter_name'])<span><i class="bi bi-person"></i> {{ $r['waiter_name'] }}</span>@endif
                    </div>
                </div>
                <div class="tb-feed-actions">
                    @if(isset($g['action']['serve']))
                        <button type="button" wire:click="serveTable({{ $r['session']->id }})"
                                wire:loading.attr="disabled" wire:target="serveTable({{ $r['session']->id }})"
                                class="tb-feed-btn"><i class="bi bi-check2-circle"></i> سلّم</button>
                    @elseif(isset($g['action']['help_ack']))
                        <button type="button" wire:click="ackHelp({{ $r['session']->id }})"
                                wire:loading.attr="disabled" wire:target="ackHelp({{ $r['session']->id }})"
                                class="tb-feed-btn"><i class="bi bi-check2-circle"></i> رحت للطاولة</button>
                    @elseif(isset($g['action']['clean']))
                        <form action="{{ route('admin.tables.mark-clean', $t) }}" method="POST" class="m-0">
                            @csrf
                            <button type="submit" class="tb-feed-btn"><i class="bi bi-check2-circle"></i> تم التنظيف</button>
                        </form>
                    @elseif(isset($g['action']['close']))
                        <form action="{{ route('admin.tables.close-session', $t) }}" method="POST" class="m-0"
                              onsubmit="return confirm('إغلاق الجلسة الراكدة على طاولة {{ $t->number }}؟ (لا مستحقات عليها)');">
                            @csrf
                            <button type="submit" class="tb-feed-btn"><i class="bi bi-x-circle"></i> أغلق الراكدة</button>
                        </form>
                    @else
                        <a href="{{ $g['action']['url'] }}" class="tb-feed-btn"><i class="bi {{ $g['action']['icon'] }}"></i> {{ $g['action']['label'] }}</a>
                    @endif
                    @include('components.admin._table-actions', [
                        't' => $t, 'session' => $r['session'], 'invoice' => $r['invoice'],
                        'activeCount' => $r['active_count'], 'availableTransferTables' => $availableTransferTables,
                        'perms' => $r['perms'],
                    ])
                </div>
            </article>
        @empty
            <div class="tb-feed-empty">
                <i class="bi bi-emoji-smile"></i>
                <span>ما في شي عاجل — كل طاولاتك تحت السيطرة</span>
            </div>
        @endforelse
    </section>

    {{-- ── Floor map: compact, section-grouped, for orientation + new tables ── --}}
    <section class="tb-map" aria-label="خريطة الصالة">
        <header class="tb-map-head">
            <i class="bi bi-layout-grid"></i>
            <h3>خريطة الصالة</h3>
        </header>

        @if($sections->isEmpty())
            <x-admin.empty-state icon="bi-grid-3x3-gap" title="لا توجد طاولات مطابقة"
                message="جرّب مسح الفلاتر أو أضف طاولة جديدة.">
                <x-slot:cta>
                    {{-- A waiter nobody rostered lands on an empty "قسمي" — the search
                         box isn't the way out, the whole-floor tab is. Say so. --}}
                    @if($view !== 'all')
                        <button wire:click="setView('all')" class="btn btn-primary me-2"><i class="bi bi-grid-3x3-gap-fill"></i> عرض كل الصالة</button>
                    @endif
                    @if($search)
                        <button wire:click="clearFilters" class="btn btn-light me-2"><i class="bi bi-x-circle"></i> مسح البحث</button>
                    @endif
                    <a href="{{ route('admin.tables.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> طاولة جديدة</a>
                </x-slot:cta>
            </x-admin.empty-state>
        @else
            @foreach($sections as $sectionName => $sectionRows)
                <div class="tb-map-section" wire:key="sec-{{ md5($sectionName) }}">
                    <div class="tb-map-section-label">{{ $sectionName }}<span>{{ $sectionRows->count() }}</span></div>
                    <div class="tb-map-grid">
                        @foreach($sectionRows as $r)
                            @php
                                $t = $r['table']; $st = $r['tile_state'];
                                $tm = $tileMeta[$st] ?? $tileMeta['occupied'];
                                // Duration::short(minutes) — «22س» not «منذ يوم و22 ساعات»,
                                // which wrapped to three lines and blew the tile open.
                                $idle = $r['session'] && $r['last_seen_at']
                                    ? Duration::short((int) abs($r['last_seen_at']->diffInMinutes(now())))
                                    : null;
                            @endphp
                            <div class="tb-tile tb-tile--{{ $st }}" wire:key="tile-{{ $t->id }}"
                                 role="group" aria-label="طاولة {{ $t->number }} · {{ $tm['label'] }}{{ $idle ? ' · '.$idle : '' }}">
                                <div class="tb-tile-top">
                                    <span class="tb-tile-num" aria-hidden="true">{{ $t->number }}</span>
                                    @include('components.admin._table-actions', [
                                        't' => $t, 'session' => $r['session'], 'invoice' => $r['invoice'],
                                        'activeCount' => $r['active_count'], 'availableTransferTables' => $availableTransferTables,
                                        'perms' => $r['perms'],
                                    ])
                                </div>
                                <div class="tb-tile-foot" aria-hidden="true">
                                    <i class="bi {{ $tm['icon'] }}"></i>
                                    <span>{{ $idle ?? $tm['label'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </section>

    {{-- Legend. Each swatch carries the tile's OWN icon, not just its colour:
         red/amber/blue are exactly the trio that collapses under the common
         colour-blindness types, and this row is where the colour language is
         taught. Glyph + colour survives; hue alone doesn't. --}}
    <div class="tb-legend">
        @foreach(['available', 'occupied', 'attention', 'cleaning', 'stale', 'urgent', 'bill'] as $st)
            <span>
                <span class="tb-lg tb-lg--{{ $st }}"><i class="bi {{ $tileMeta[$st]['icon'] }}"></i></span>
                {{ $tileMeta[$st]['label'] }}
            </span>
        @endforeach
    </div>
</div>
{{-- Styles in public/assets/dashtic/css/tables-board.css, loaded in <head> by
     admin/tables/index.blade.php (@push('styles')) — a component-body <style>
     flashed unstyled content for a beat. --}}
