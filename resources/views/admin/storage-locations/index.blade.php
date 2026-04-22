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

<div class="row g-3">
    @forelse($locations as $loc)
        <div class="col-md-4">
            <div class="data-panel h-100">
                <div class="data-panel-head" style="background: linear-gradient(90deg, {{ $loc->color ?? 'var(--primary)' }}15, transparent 60%);">
                    <div class="data-panel-head-start">
                        <span class="data-panel-icon" style="background: {{ $loc->color ?? 'var(--accent)' }}22; color: {{ $loc->color ?? 'var(--accent)' }};">
                            <i class="bi {{ $loc->icon ?: 'bi-geo-alt' }}"></i>
                        </span>
                        <div>
                            <h3 class="data-panel-title" style="color: {{ $loc->color ?? 'var(--primary)' }};">
                                {{ $loc->name }}
                                @if($loc->is_default)<span class="badge bg-accent" style="background:rgba(var(--accent-rgb),.15); color:#8a6920;">افتراضي</span>@endif
                            </h3>
                            <small class="text-muted" style="font-family:monospace;">{{ $loc->code }}</small>
                        </div>
                    </div>
                </div>
                <div class="p-3">
                    @if($loc->description)
                        <p class="small text-muted mb-3">{{ $loc->description }}</p>
                    @endif

                    <div class="row g-2 mb-3 text-center">
                        <div class="col-6">
                            <div class="small text-muted fw-bold">المكونات</div>
                            <div class="fw-bold fs-4" style="color: var(--primary);">{{ $loc->ingredient_stocks_count }}</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted fw-bold">مخزون منخفض</div>
                            <div class="fw-bold fs-4" style="color: {{ $loc->low_stock_count > 0 ? '#b91c1c' : 'var(--primary)' }};">
                                {{ $loc->low_stock_count }}
                            </div>
                        </div>
                    </div>

                    <div class="p-2 rounded" style="background: rgba(var(--accent-rgb), .08); text-align: center;">
                        <div class="small text-muted fw-bold">قيمة المخزون</div>
                        <div class="fw-bold fs-4" style="color: var(--accent); font-variant-numeric: tabular-nums;">
                            {{ \App\Helpers\Money::format($loc->stock_value) }}
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <a href="{{ route('admin.storage-locations.show', $loc) }}" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-eye"></i> تفاصيل
                        </a>
                        <a href="{{ route('admin.storage-locations.edit', $loc) }}" class="btn btn-light"><i class="bi bi-pencil"></i></a>
                        @if(!$loc->is_default)
                            <form action="{{ route('admin.storage-locations.destroy', $loc) }}" method="POST" onsubmit="return confirm('حذف الموقع؟');">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        @endif
                    </div>
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
