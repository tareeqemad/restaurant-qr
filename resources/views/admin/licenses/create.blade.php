@extends('layouts.admin')

@section('title', 'ترخيص جديد')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">ترخيص جديد</h1>
            <div class="text-muted small">بعد الحفظ يظهر مفتاح الترخيص الذي يوضع على جهاز العميل.</div>
        </div>
        <a href="{{ route('admin.licenses.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right"></i> رجوع
        </a>
    </div>

    <form method="POST" action="{{ route('admin.licenses.store') }}" class="card">
        @csrf
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                <input type="text" name="customer_name" value="{{ old('customer_name') }}" class="form-control @error('customer_name') is-invalid @enderror" required>
                @error('customer_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">اسم المطعم</label>
                <input type="text" name="restaurant_name" value="{{ old('restaurant_name') }}" class="form-control @error('restaurant_name') is-invalid @enderror">
                @error('restaurant_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">هاتف العميل</label>
                <input type="text" name="customer_phone" value="{{ old('customer_phone') }}" class="form-control @error('customer_phone') is-invalid @enderror">
                @error('customer_phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label class="form-label">البريد الإلكتروني</label>
                <input type="email" name="customer_email" value="{{ old('customer_email') }}" class="form-control @error('customer_email') is-invalid @enderror">
                @error('customer_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label">مدة الترخيص <span class="text-danger">*</span></label>
                <select name="period_months" class="form-select @error('period_months') is-invalid @enderror" required>
                    <option value="12" @selected((string) old('period_months', '12') === '12')>سنة</option>
                    <option value="6" @selected((string) old('period_months') === '6')>6 شهور</option>
                </select>
                @error('period_months') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label">تاريخ البداية <span class="text-danger">*</span></label>
                <input type="date" name="starts_at" value="{{ old('starts_at', now()->toDateString()) }}" class="form-control @error('starts_at') is-invalid @enderror" required>
                @error('starts_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label">قيمة الدفعة النقدية</label>
                <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="form-control @error('amount') is-invalid @enderror">
                @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label">عدد الفروع المسموح</label>
                <input type="number" min="1" max="250" name="max_branches" value="{{ old('max_branches', 1) }}" class="form-control @error('max_branches') is-invalid @enderror" required>
                @error('max_branches') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label">فترة السماح بعد الانتهاء</label>
                <input type="number" min="0" max="90" name="grace_days" value="{{ old('grace_days', config('license.grace_days', 14)) }}" class="form-control @error('grace_days') is-invalid @enderror" required>
                @error('grace_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label class="form-label">ملاحظات</label>
                <textarea name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
        <div class="card-footer text-start">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> إنشاء الترخيص
            </button>
        </div>
    </form>
</div>
@endsection
