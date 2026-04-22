<?php

use App\Models\Table;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tables admin board — compact grid with live search, status filters, zones.
 *
 * Replaces the previous noisy table grid: each card now shows a colored dot
 * (status), big number, capacity, optional session info, and 3-4 icon-only
 * actions — no stacked badges, no giant status labels.
 */
new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'zone', except: '')]
    public string $zoneFilter = '';

    #[Computed]
    public function tables()
    {
        $q = Table::with('activeSession.orders')->orderBy('number');

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('number', 'like', "%{$s}%")
                   ->orWhere('name',   'like', "%{$s}%")
                   ->orWhere('zone',   'like', "%{$s}%");
            });
        }
        if ($this->statusFilter !== 'all') {
            $q->where('status', $this->statusFilter);
        }
        if ($this->zoneFilter !== '') {
            $q->where('zone', $this->zoneFilter);
        }

        return $q->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'all'            => Table::count(),
            'available'      => Table::where('status', 'available')->count(),
            'occupied'       => Table::where('status', 'occupied')->count(),
            'reserved'       => Table::where('status', 'reserved')->count(),
            'out_of_service' => Table::where('status', 'out_of_service')->count(),
        ];
    }

    #[Computed]
    public function zones(): array
    {
        return Table::whereNotNull('zone')->where('zone', '!=', '')
            ->distinct()->pluck('zone')->toArray();
    }

    public function setStatus(string $s): void { $this->statusFilter = $s; }
    public function setZone(string $z): void   { $this->zoneFilter   = $this->zoneFilter === $z ? '' : $z; }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'zoneFilter']);
        $this->statusFilter = 'all';
    }
}
?>

<div class="tables-board" wire:poll.10s>
    {{-- Filter bar --}}
    <div class="tb-filters">
        <div class="tb-status-chips">
            @php
                $statuses = [
                    'all'            => ['كل الطاولات', 'bi-grid-3x3-gap',   'muted'],
                    'available'      => ['متاحة',       'bi-check-circle',  'success'],
                    'occupied'       => ['مشغولة',      'bi-people-fill',   'accent'],
                    'reserved'       => ['محجوزة',      'bi-bookmark-fill', 'info'],
                    'out_of_service' => ['خارج الخدمة', 'bi-x-circle',      'danger'],
                ];
            @endphp
            @foreach($statuses as $key => [$label, $icon, $color])
                <button type="button"
                    wire:click="setStatus('{{ $key }}')"
                    class="tb-chip tb-chip--{{ $color }} {{ $statusFilter === $key ? 'is-active' : '' }}">
                    <i class="bi {{ $icon }}"></i>
                    <span>{{ $label }}</span>
                    <span class="tb-chip-count">{{ $this->stats[$key] }}</span>
                </button>
            @endforeach
        </div>

        <div class="tb-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text"
                wire:model.live.debounce.250ms="search"
                placeholder="ابحث برقم الطاولة..."
                class="tb-search">
            @if($search)
                <button type="button" wire:click="$set('search', '')" class="tb-search-clear" title="مسح">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
        </div>
    </div>

    {{-- Zone chips (if any zones exist) --}}
    @if(count($this->zones))
        <div class="tb-zone-chips">
            <small class="text-muted fw-bold me-2">المناطق:</small>
            @foreach($this->zones as $z)
                <button type="button" wire:click="setZone('{{ $z }}')"
                    class="tb-zone-chip {{ $zoneFilter === $z ? 'is-active' : '' }}">
                    {{ $z }}
                </button>
            @endforeach
            @if($zoneFilter)
                <button type="button" wire:click="$set('zoneFilter', '')" class="tb-zone-clear" title="مسح">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
        </div>
    @endif

    {{-- Grid --}}
    @php $tables = $this->tables; @endphp
    @if($tables->isEmpty())
        <x-admin.empty-state
            icon="bi-grid-3x3-gap"
            title="لا طاولات مطابقة"
            message="جرّب مسح الفلاتر أو إضافة طاولة جديدة.">
            <x-slot:cta>
                <button wire:click="clearFilters" class="btn btn-light me-2">
                    <i class="bi bi-x-circle"></i> مسح الفلاتر
                </button>
                <a href="{{ route('admin.tables.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> طاولة جديدة
                </a>
            </x-slot:cta>
        </x-admin.empty-state>
    @else
        <div class="tb-grid">
            @foreach($tables as $t)
                @php
                    $statusMap = [
                        'available'      => ['color' => '#10b981', 'label' => 'متاحة'],
                        'occupied'       => ['color' => '#b8872a', 'label' => 'مشغولة'],
                        'reserved'       => ['color' => '#3b82f6', 'label' => 'محجوزة'],
                        'out_of_service' => ['color' => '#9ca3af', 'label' => 'خارج الخدمة'],
                    ];
                    $meta = $statusMap[$t->status] ?? ['color' => '#6b7280', 'label' => $t->status];
                    $session = $t->activeSession;
                    $orderCount = $session?->orders?->count() ?? 0;
                @endphp

                <div class="tb-card" style="--status-color: {{ $meta['color'] }};">
                    <div class="tb-card-head">
                        <span class="tb-status-dot" title="{{ $meta['label'] }}"></span>
                        <div class="tb-num">{{ $t->number }}</div>
                        <span class="tb-capacity" title="سعة الطاولة">
                            <i class="bi bi-people-fill"></i>
                            {{ $t->capacity }}
                        </span>
                    </div>

                    @if($t->name || $t->zone)
                        <div class="tb-sub">
                            @if($t->name)<span class="tb-name">{{ $t->name }}</span>@endif
                            @if($t->zone)<small class="tb-zone"><i class="bi bi-geo-alt"></i> {{ $t->zone }}</small>@endif
                        </div>
                    @endif

                    @if($session && $orderCount > 0)
                        <div class="tb-session-info">
                            <i class="bi bi-receipt-cutoff"></i>
                            {{ $orderCount }} {{ $orderCount === 1 ? 'طلب' : 'طلبات' }}
                            <span class="tb-session-time">{{ $session->opened_at->diffForHumans() }}</span>
                        </div>
                    @endif

                    <div class="tb-actions">
                        <a href="{{ route('admin.tables.qr-print', $t) }}" target="_blank" class="tb-btn" title="طباعة QR">
                            <i class="bi bi-qr-code"></i>
                        </a>
                        <a href="{{ route('admin.tables.edit', $t) }}" class="tb-btn" title="تعديل">
                            <i class="bi bi-pencil"></i>
                        </a>
                        @if($session && $orderCount > 0)
                            <a href="{{ route('admin.cashier.show', $session) }}" class="tb-btn tb-btn-primary" title="كاشير">
                                <i class="bi bi-cash-stack"></i>
                            </a>
                        @endif
                        <form action="{{ route('admin.tables.destroy', $t) }}" method="POST" class="d-inline"
                            onsubmit="return confirm('تأكيد حذف الطاولة {{ $t->number }}؟')">
                            @csrf @method('DELETE')
                            <button type="submit" class="tb-btn tb-btn-danger" title="حذف">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
