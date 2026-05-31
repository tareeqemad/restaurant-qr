@extends('layouts.admin')

@section('title', 'إضافة أصل ثابت')

@section('content')
<x-admin.breadcrumb
    title="إضافة أصل ثابت"
    icon="bi-plus-circle"
    subtitle="إثبات أصل رأسمالي وترحيل قيد الشراء تلقائيا"
    :crumbs="[
        ['label' => 'الأصول الثابتة', 'url' => route('admin.accounting.fixed-assets.index')],
        ['label' => 'إضافة أصل']
    ]" />

<form method="POST" action="{{ route('admin.accounting.fixed-assets.store') }}" class="card">
    @csrf
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">رقم الأصل</label>
                <input type="text" name="asset_number" value="{{ old('asset_number', $asset->asset_number) }}" class="form-control">
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold">اسم الأصل</label>
                <input type="text" name="name" value="{{ old('name') }}" class="form-control" required placeholder="مثلا: فرن بيتزا، ثلاجة، طاولة تحضير">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">الفئة</label>
                <input type="text" name="category" value="{{ old('category') }}" class="form-control" placeholder="معدات، أثاث، أجهزة">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">تاريخ الشراء</label>
                <input type="date" name="acquisition_date" value="{{ old('acquisition_date', $asset->acquisition_date?->toDateString()) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">تاريخ بدء الاستخدام</label>
                <input type="date" name="in_service_date" value="{{ old('in_service_date', $asset->in_service_date?->toDateString()) }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">العمر الإنتاجي بالأشهر</label>
                <input type="number" name="useful_life_months" value="{{ old('useful_life_months', $asset->useful_life_months) }}" min="1" max="600" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">طريقة الدفع</label>
                <select name="payment_method" class="form-select" required>
                    @foreach($paymentMethods as $method => $label)
                        <option value="{{ $method }}" @selected(old('payment_method', $asset->payment_method) === $method)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">العملة</label>
                <select name="currency_code" class="form-select" required>
                    @foreach($currencies as $currency)
                        <option value="{{ $currency->code }}" @selected(old('currency_code', $asset->currency_code) === $currency->code)>
                            {{ $currency->code }} - {{ $currency->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">سعر الصرف</label>
                <input type="number" step="0.00000001" min="0" name="exchange_rate" value="{{ old('exchange_rate', $asset->exchange_rate) }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">تكلفة الأصل</label>
                <input type="number" step="0.01" min="0" name="foreign_cost" value="{{ old('foreign_cost') }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">القيمة التخريدية</label>
                <input type="number" step="0.01" min="0" name="foreign_salvage_value" value="{{ old('foreign_salvage_value', 0) }}" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-bold">المورد</label>
                <select name="supplier_id" class="form-select">
                    <option value="">بدون ربط مورد</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) old('supplier_id') === (int) $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">اسم المورد/البائع</label>
                <input type="text" name="vendor_name" value="{{ old('vendor_name') }}" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">وصف مختصر</label>
                <input type="text" name="description" value="{{ old('description') }}" class="form-control">
            </div>

            <div class="col-12">
                <label class="form-label fw-bold">ملاحظات</label>
                <textarea name="notes" rows="3" class="form-control">{{ old('notes') }}</textarea>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a href="{{ route('admin.accounting.fixed-assets.index') }}" class="btn btn-outline-secondary">إلغاء</a>
        <button class="btn btn-primary"><i class="bi bi-journal-check"></i> حفظ وترحيل قيد الشراء</button>
    </div>
</form>
@endsection
