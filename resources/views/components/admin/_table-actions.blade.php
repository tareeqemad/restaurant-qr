{{-- Per-table action menu (Alpine popover) — reused by BOTH the triage feed
     cards and the floor-map tiles, so every table is fully manageable from
     wherever the waiter's eye lands. Alpine (not Bootstrap JS) so the board's
     15s poll + Livewire morph can't strip an open menu mid-interaction.

     Expects: $t (Table) and $perms (['update'=>bool,'transfer'=>bool,'delete'=>bool]),
     optionally $session, $invoice, $activeCount, $availableTransferTables.

     $perms is passed IN rather than re-checked with @can here on purpose: this
     partial renders up to ~80× per board (every tile + every feed card), and
     TablePolicy's gates run User::belongsToBranch — an un-memoized DB query for
     any non-owner. The component resolves them once per branch instead. --}}
@php
    $ta_session = $session ?? $t->activeSession;
    $ta_invoice = $invoice ?? $ta_session?->invoice;
    $ta_active = $activeCount ?? ($ta_session?->orders?->count() ?? 0);
    $ta_transfer = $availableTransferTables ?? collect();
    $ta_perms = ($perms ?? []) + ['update' => false, 'transfer' => false, 'delete' => false];
@endphp
{{-- flip: a 190px menu anchored to a 78px map tile runs off-screen on the edge
     columns (it opens toward one side only). Measure once on open and flip to
     the other side if it would spill past the viewport. --}}
<div class="dropdown ta-more"
     x-data="{
        open: false,
        flip: false,
        toggle() {
            this.open = !this.open;
            if (! this.open) return;
            this.flip = false;
            this.$nextTick(() => {
                const m = this.$refs.menu;
                if (! m) return;
                const r = m.getBoundingClientRect();
                this.flip = r.left < 8 || r.right > window.innerWidth - 8;
            });
        }
     }"
     @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="ta-more-btn" @click.stop="toggle()"
        :aria-expanded="open ? 'true' : 'false'"
        aria-label="إجراءات طاولة {{ $t->number }}">
        <i class="bi bi-three-dots"></i>
    </button>
    <ul class="dropdown-menu ta-more-menu" x-ref="menu" :class="{ show: open, 'is-flipped': flip }">
        @if($ta_session && ($ta_invoice !== null || $ta_active > 0))
            <li><a class="dropdown-item" href="{{ route('admin.cashier.show', $ta_session) }}"><i class="bi bi-cash-stack"></i> كاشير</a></li>
        @endif
        @if($ta_session && $ta_active > 0)
            {{-- Carry the table: OrderController@index filters on table_id, and
                 sending a waiter to the branch-wide list to find their own table
                 by hand is the thing this board exists to stop. --}}
            <li><a class="dropdown-item" href="{{ route('admin.orders.index', ['table_id' => $t->id]) }}"><i class="bi bi-person-check-fill"></i> مهام الجرسون</a></li>
        @endif
        <li><a class="dropdown-item" href="{{ route('admin.waiter-orders.create', $t) }}"><i class="bi bi-plus-lg"></i> {{ $ta_session ? 'إضافة طلب' : 'تشغيل الطاولة' }}</a></li>
        <li><a class="dropdown-item" href="{{ route('admin.tables.qr-print', $t) }}"><i class="bi bi-qr-code"></i> طباعة QR</a></li>
        @if($ta_perms['update'])
            <li><a class="dropdown-item" href="{{ route('admin.tables.edit', $t) }}"><i class="bi bi-pencil-square"></i> تعديل الطاولة</a></li>
        @endif
        @if($ta_perms['transfer'] && $ta_session && $ta_transfer->isNotEmpty())
            <li><hr class="dropdown-divider"></li>
            <li class="ta-more-transfer">
                <form action="{{ route('admin.tables.transfer', $t) }}" method="POST"
                    onsubmit="return confirm('نقل جلسة طاولة {{ $t->number }} إلى الطاولة المختارة؟');">
                    @csrf
                    <span class="ta-more-transfer-label"><i class="bi bi-arrow-left-right"></i> نقل الجلسة إلى</span>
                    <div class="ta-more-transfer-row">
                        <select name="target_table_id" class="ta-transfer-select" required aria-label="نقل إلى طاولة">
                            <option value="">اختر طاولة...</option>
                            @foreach($ta_transfer as $availableTable)
                                <option value="{{ $availableTable->id }}">طاولة {{ $availableTable->number }}{{ $availableTable->name ? ' - '.$availableTable->name : '' }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="ta-transfer-go">نقل</button>
                    </div>
                </form>
            </li>
        @endif
        @if($ta_perms['delete'])
            <li><hr class="dropdown-divider"></li>
            <li>
                <form action="{{ route('admin.tables.destroy', $t) }}" method="POST" onsubmit="return confirm('تأكيد حذف الطاولة {{ $t->number }}؟')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="dropdown-item ta-more-danger"><i class="bi bi-trash"></i> حذف الطاولة</button>
                </form>
            </li>
        @endif
    </ul>
</div>
