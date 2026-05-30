@extends('layouts.admin')

@section('title', 'حالة الترخيص')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">حالة الترخيص</h1>
            <div class="text-muted small">هذا الجهاز يفحص الترخيص من السحابة ويحفظ نسخة موقّعة للعمل أثناء انقطاع النت.</div>
        </div>
        <form method="POST" action="{{ route('admin.license-status.refresh') }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-repeat"></i> فحص الآن
            </button>
        </form>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif
    @if($errors->has('license'))
        <div class="alert alert-danger">{{ $errors->first('license') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                        <h2 class="h6 mb-0">الوضع الحالي</h2>
                        <span class="badge bg-{{ $summary['color'] }}">{{ $summary['label'] }}</span>
                    </div>

                    <p class="mb-3">{{ $summary['message'] }}</p>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">تفعيل النظام</dt>
                        <dd class="col-sm-8">{{ $enabled ? 'مفعّل' : 'غير مفعّل' }}</dd>

                        <dt class="col-sm-4">دور الجهاز</dt>
                        <dd class="col-sm-8">{{ $role }}</dd>

                        <dt class="col-sm-4">السحابة</dt>
                        <dd class="col-sm-8">{{ $cloudUrl ?: '—' }}</dd>

                        <dt class="col-sm-4">مفتاح الترخيص</dt>
                        <dd class="col-sm-8"><code>{{ $configuredKey ?: '—' }}</code></dd>

                        <dt class="col-sm-4">ينتهي في</dt>
                        <dd class="col-sm-8">{{ $state->expires_at?->format('Y-m-d') ?: '—' }}</dd>

                        <dt class="col-sm-4">نهاية السماح</dt>
                        <dd class="col-sm-8">{{ $state->grace_ends_at?->format('Y-m-d') ?: '—' }}</dd>

                        <dt class="col-sm-4">آخر فحص</dt>
                        <dd class="col-sm-8">{{ $state->last_checked_at?->format('Y-m-d H:i') ?: '—' }}</dd>

                        <dt class="col-sm-4">آخر وقت من السحابة</dt>
                        <dd class="col-sm-8">{{ $state->last_server_time_at?->format('Y-m-d H:i') ?: '—' }}</dd>

                        @if($state->last_error)
                            <dt class="col-sm-4">آخر خطأ</dt>
                            <dd class="col-sm-8 text-danger">{{ $state->last_error }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-3">مفتاح العميل</h2>

                    @if($envKeyLocked)
                        <div class="alert alert-info mb-0">
                            المفتاح مضبوط من ملف البيئة ولا يمكن تغييره من اللوحة.
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.license-status.key') }}">
                            @csrf
                            <label class="form-label">مفتاح الترخيص</label>
                            <input type="text" name="license_key" value="{{ old('license_key', $state->license_key) }}" class="form-control @error('license_key') is-invalid @enderror" required>
                            @error('license_key') <div class="invalid-feedback">{{ $message }}</div> @enderror

                            <button type="submit" class="btn btn-outline-primary mt-3">
                                <i class="bi bi-save"></i> حفظ المفتاح
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="alert alert-light border mt-3 mb-0">
                تظهر التنبيهات قبل {{ $warningDays }} يوم من الانتهاء. بعد تاريخ الانتهاء تبقى عمليات البيع والطلبات ضمن فترة السماح فقط، وبعدها تتوقف عمليات الكتابة حتى يتم التجديد من السحابة.
            </div>
        </div>
    </div>
</div>
@endsection
