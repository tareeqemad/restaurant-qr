@extends('layouts.admin')
@section('title', 'جرد جديد')

@section('content')
<x-admin.breadcrumb title="جرد جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الجرد', 'url' => route('admin.stock-counts.index')]]" />

<x-admin.data-panel title="جرد جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.stock-counts.index') }}" class="btn btn-light">
            <i class="bi bi-arrow-right"></i> رجوع للقائمة
        </a>
    </x-slot:actions>

    <div class="alert" style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
        <i class="bi bi-info-circle-fill"></i>
        سيتم إنشاء جرد يشمل <strong>{{ $ingredientCount }}</strong> مكوّن متتبع.
        اختر موقعاً إذا كان الجرد لمخزن/محطة محددة، أو اتركه عاماً لجرد إجمالي الفرع.
    </div>

    <form method="POST" action="{{ route('admin.stock-counts.store') }}">
        @csrf

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">تاريخ الجرد <span class="req">*</span></label>
                <input type="date" name="count_date" value="{{ old('count_date', today()->toDateString()) }}" class="form-control form-control-lg" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">موقع الجرد</label>
                <select name="storage_location_id" class="form-select form-select-lg" data-relax-choice data-choice-search-placeholder="ابحث عن موقع...">
                    <option value="">جرد إجمالي الفرع</option>
                    @foreach($storageLocations as $location)
                        <option value="{{ $location->id }}" @selected(old('storage_location_id') == $location->id)>
                            {{ $location->name }}{{ $location->code ? ' - '.$location->code : '' }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">للمطبخ أو البار أو المخزن الرئيسي حسب الواقع.</small>
            </div>

            <div class="col-12">
                <label class="form-label">ملاحظات</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="مثلاً: جرد شهري، جرد نهاية الشفت...">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="form-actions mt-4">
            <a href="{{ route('admin.stock-counts.index') }}" class="btn btn-light">
                <i class="bi bi-arrow-right"></i> إلغاء
            </a>
            <button class="btn btn-primary">
                <i class="bi bi-play-circle-fill"></i> بدء الجرد
            </button>
        </div>
    </form>
</x-admin.data-panel>
@endsection
