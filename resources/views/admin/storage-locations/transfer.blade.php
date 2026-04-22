@extends('layouts.admin')
@section('title', 'نقل مخزون بين المواقع')

@section('content')
<x-admin.breadcrumb title="نقل بين المواقع" icon="bi-arrow-left-right"
    :crumbs="[['label' => 'مواقع التخزين', 'url' => route('admin.storage-locations.index')]]" />

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <div class="alert" style="background:rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
                    <i class="bi bi-info-circle-fill"></i>
                    نقل المخزون ينشئ حركتين: <strong>out</strong> من الموقع المصدر و <strong>in</strong> للموقع الوجهة في نفس اللحظة.
                </div>

                <form method="POST" action="{{ route('admin.storage-locations.transfer-store') }}">
                    @csrf

                    <div class="form-section">
                        <div class="form-section-head">
                            <i class="bi bi-arrow-left-right"></i>
                            <span>تفاصيل النقل</span>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">المكوّن <span class="req">*</span></label>
                            <select name="ingredient_id" class="form-select form-select-lg" required>
                                <option value="">— اختر مكون —</option>
                                @foreach($ingredients as $ing)
                                    <option value="{{ $ing->id }}" @selected(old('ingredient_id')==$ing->id)>
                                        {{ $ing->name }} ({{ $ing->baseUnit?->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">من الموقع <span class="req">*</span></label>
                                <select name="from_id" class="form-select form-select-lg" required>
                                    <option value="">— المصدر —</option>
                                    @foreach($locations as $loc)
                                        <option value="{{ $loc->id }}" @selected(old('from_id')==$loc->id)>
                                            {{ $loc->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-2 text-center">
                                <div style="padding-top: 2.4rem;">
                                    <i class="bi bi-arrow-left-circle-fill" style="font-size: 2rem; color: var(--accent);"></i>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label">إلى الموقع <span class="req">*</span></label>
                                <select name="to_id" class="form-select form-select-lg" required>
                                    <option value="">— الوجهة —</option>
                                    @foreach($locations as $loc)
                                        <option value="{{ $loc->id }}" @selected(old('to_id')==$loc->id)>
                                            {{ $loc->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">الكمية <span class="req">*</span></label>
                            <input type="number" step="0.0001" min="0.0001" name="quantity" value="{{ old('quantity') }}" class="form-control form-control-lg" required>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">سبب النقل (اختياري)</label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="مثلاً: نقل للاستخدام في البار، توزيع مخزون...">{{ old('reason') }}</textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('admin.storage-locations.index') }}" class="btn btn-light">إلغاء</a>
                        <button class="btn btn-primary btn-lg">
                            <i class="bi bi-arrow-left-right"></i> تنفيذ النقل
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
