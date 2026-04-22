@extends('layouts.admin')
@section('title', 'جرد جديد')

@section('content')
<x-admin.breadcrumb title="جرد جديد" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'الجرد', 'url' => route('admin.stock-counts.index')]]" />

<x-admin.data-panel title="جرد جديد" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.stock-counts.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <div class="alert" style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
            <i class="bi bi-info-circle-fill"></i>
            سيتم إنشاء جرد يشمل <strong>{{ $ingredientCount }}</strong> مكوّن (كل المكونات المتتبعة).
            بعد الإنشاء، تُدخل الكمية الفعلية لكل مكوّن ثم تعتمد الجرد لتطبيق التسويات.
        </div>

        <form method="POST" action="{{ route('admin.stock-counts.store') }}">
            @csrf

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">تاريخ الجرد <span class="req">*</span></label>
                    <input type="date" name="count_date" value="{{ today()->toDateString() }}" class="form-control form-control-lg" required>
                </div>
                <div class="col-12">
                    <label class="form-label">ملاحظات (اختيارية)</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="مثلاً: جرد شهري، إغلاق السنة المالية..."></textarea>
</x-admin.data-panel>

            <div class="form-actions">
                <a href="{{ route('admin.stock-counts.index') }}" class="btn btn-light">
                    <i class="bi bi-arrow-right"></i> إلغاء
                </a>
                <button class="btn btn-primary">
                    <i class="bi bi-play-circle-fill"></i> بدء الجرد
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
