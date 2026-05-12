@extends('layouts.admin')
@section('title', 'مواقع التخزين')

@section('content')
<x-admin.breadcrumb title="مواقع التخزين" icon="bi-geo-alt-fill"
    subtitle="إدارة المخازن وتوزيع المخزون بينها">
    <x-slot:actions>
        <a href="{{ route('admin.storage-locations.transfer-form') }}" class="btn btn-light">
            <i class="bi bi-arrow-left-right"></i> نقل بين المواقع
        </a>
        <a href="{{ route('admin.storage-locations.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> موقع جديد
        </a>
    </x-slot:actions>
</x-admin.breadcrumb>

<style>
    .loc-card {
        background: #fff;
        border: 1px solid rgba(15, 71, 49, .08);
        border-radius: 14px;
        overflow: hidden;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        display: flex;
        flex-direction: column;
        height: 100%;
        position: relative;
    }
    .loc-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 14px 32px rgba(15, 71, 49, .09);
        border-color: rgba(15, 71, 49, .16);
    }
    .loc-card.is-inactive { opacity: .65; }
    .loc-card.is-default::before {
        content: '';
        position: absolute;
        top: 0; bottom: 0;
        inset-inline-start: 0;
        width: 4px;
        background: var(--accent);
    }

    .loc-card-head {
        position: relative;
        padding: 1.15rem 1.15rem 1rem;
        color: #fff !important;
        overflow: hidden;
    }
    .loc-card-head .name {
        font-size: 1.35rem;
        font-weight: 800;
        margin: 0;
        line-height: 1.25;
        color: #fff !important;
    }
    .loc-card-head .meta {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem .65rem;
        align-items: center;
        margin-top: .5rem;
        font-size: 12.5px;
        color: rgba(255, 255, 255, .92) !important;
    }
    .loc-card-head .meta .code {
        font-family: ui-monospace, SFMono-Regular, monospace;
        background: rgba(255, 255, 255, .2);
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 11.5px;
        letter-spacing: 0;
    }
    .loc-card-head .meta .branch {
        display: inline-flex; align-items: center; gap: .3rem;
        color: rgba(255, 255, 255, .92);
    }
    .loc-card-head .badges {
        position: absolute;
        top: .9rem;
        inset-inline-start: 1rem;
        display: flex;
        gap: .35rem;
    }
    .loc-card-head .pill {
        font-size: 10.5px;
        font-weight: 700;
        background: rgba(255, 255, 255, .22);
        backdrop-filter: blur(4px);
        color: #fff !important;
        padding: 3px 9px;
        border-radius: 999px;
        letter-spacing: 0;
        display: inline-flex;
        align-items: center;
        gap: .25rem;
    }
    .loc-card-head .pill.muted {
        background: rgba(0, 0, 0, .2);
    }

    .loc-card-body { padding: 1rem 1.1rem; flex-grow: 1; }
    .loc-card-desc {
        font-size: 12.5px;
        color: #6c757d;
        line-height: 1.55;
        margin-bottom: .85rem;
        min-height: 1em;
    }

    .loc-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .55rem;
        margin-bottom: .85rem;
    }
    .loc-stat {
        background: rgba(15, 71, 49, .04);
        border-radius: 10px;
        padding: .55rem .75rem;
        text-align: center;
    }
    .loc-stat .label {
        font-size: 11px;
        color: #6c757d;
        font-weight: 600;
        margin-bottom: 2px;
    }
    .loc-stat .value {
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1;
        color: var(--primary);
    }
    .loc-stat.is-warn { background: rgba(220, 53, 69, .06); }
    .loc-stat.is-warn .value { color: #b02a37; }

    .loc-value-card {
        background: linear-gradient(135deg, rgba(15, 71, 49, .06), rgba(15, 71, 49, .015));
        border: 1px solid rgba(15, 71, 49, .1);
        border-radius: 10px;
        padding: .75rem 1rem;
        text-align: center;
    }
    .loc-value-card .label {
        font-size: 11.5px;
        color: #6c757d;
        font-weight: 600;
        margin-bottom: 3px;
    }
    .loc-value-card .amount {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--primary);
        font-variant-numeric: tabular-nums;
        line-height: 1.05;
    }
    .loc-value-card .currency {
        font-size: 14px;
        color: #6c757d;
        font-weight: 600;
        margin-inline-start: 3px;
    }

    .loc-card-actions {
        display: flex;
        gap: .4rem;
        padding: .7rem 1.1rem .9rem;
        border-top: 1px solid rgba(15, 71, 49, .06);
        background: #fafbfa;
    }
    .loc-card-actions .btn-icon {
        width: 38px; height: 38px;
        display: inline-flex; align-items: center; justify-content: center;
        padding: 0;
    }
</style>

<div class="row g-3">
    @forelse($locations as $loc)
        @php
            $color    = $loc->color ?? '#0F4731';
            $accent   = $color;
            $headerBg = "linear-gradient(135deg, {$color} 0%, " . $color . "dd 100%)";
        @endphp
        <div class="col-lg-4 col-md-6">
            <div class="loc-card @if($loc->is_default) is-default @endif @if(!$loc->active) is-inactive @endif">
                {{-- Header --}}
                <div class="loc-card-head" style="background: {{ $headerBg }};">
                    <div class="badges">
                        @if($loc->is_default)
                            <span class="pill" title="الموقع الافتراضي للفرع">
                                <i class="bi bi-star-fill"></i> افتراضي
                            </span>
                        @endif
                        @if(!$loc->active)
                            <span class="pill muted">
                                <i class="bi bi-pause-circle-fill"></i> غير نشط
                            </span>
                        @endif
                    </div>
                    <h3 class="name">{{ $loc->name }}</h3>
                    <div class="meta">
                        @if($loc->code)
                            <span class="code">{{ $loc->code }}</span>
                        @endif
                        @if($loc->branch)
                            <span class="branch">
                                <i class="bi bi-building"></i>
                                {{ $loc->branch->name }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Body --}}
                <div class="loc-card-body">
                    <div class="loc-card-desc">
                        {{ $loc->description ?: 'لا يوجد وصف' }}
                    </div>

                    <div class="loc-stats">
                        <div class="loc-stat" title="عدد المكونات المخزّنة في هذا الموقع">
                            <div class="label">المكونات</div>
                            <div class="value">{{ $loc->ingredient_stocks_count }}</div>
                        </div>
                        <div class="loc-stat @if($loc->low_stock_count > 0) is-warn @endif"
                             title="مكونات وصلت أو هبطت تحت حد إعادة الطلب">
                            <div class="label">
                                @if($loc->low_stock_count > 0)
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                @endif
                                مخزون منخفض
                            </div>
                            <div class="value">{{ $loc->low_stock_count }}</div>
                        </div>
                    </div>

                    <div class="loc-value-card" title="إجمالي قيمة المخزون = الكميات × التكلفة">
                        <div class="label"><i class="bi bi-coin"></i> قيمة المخزون</div>
                        <div class="amount">
                            {{ number_format((float) $loc->stock_value, 2) }}
                            <span class="currency">{{ config('restaurant.currency_symbol', '₪') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="loc-card-actions">
                    <a href="{{ route('admin.storage-locations.show', $loc) }}" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-eye"></i> تفاصيل
                    </a>
                    <a href="{{ route('admin.storage-locations.edit', $loc) }}"
                       class="btn btn-light btn-icon" title="تعديل">
                        <i class="bi bi-pencil"></i>
                    </a>
                    @if(!$loc->is_default)
                        <form action="{{ route('admin.storage-locations.destroy', $loc) }}" method="POST"
                              onsubmit="return confirm('حذف الموقع «{{ $loc->name }}»؟');"
                              class="m-0">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-icon" title="حذف">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <x-admin.empty-state
                icon="bi-geo-alt"
                title="ما في مواقع تخزين بعد"
                message="أضف مواقع (مطبخ، بار، ثلاجة) لتوزيع المخزون عليها.">
                <x-slot:cta>
                    <a href="{{ route('admin.storage-locations.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i> موقع جديد
                    </a>
                </x-slot:cta>
            </x-admin.empty-state>
        </div>
    @endforelse
</div>
@endsection
