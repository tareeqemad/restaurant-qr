@extends('layouts.admin')

@section('title', 'تفاصيل الترخيص')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $license->customer_name }}</h1>
            <div class="text-muted small">{{ $license->restaurant_name ?: 'بدون اسم مطعم' }}</div>
        </div>
        <a href="{{ route('admin.licenses.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-right"></i> رجوع
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between gap-3">
                        <div>
                            <div class="text-muted small mb-1">مفتاح الترخيص</div>
                            <code class="fs-6">{{ $license->license_key }}</code>
                        </div>
                        <span class="badge bg-{{ $license->statusColor() }}">{{ $license->statusLabel() }}</span>
                    </div>

                    <hr>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">بداية الترخيص</dt>
                        <dd class="col-sm-8">{{ $license->starts_at?->format('Y-m-d') }}</dd>

                        <dt class="col-sm-4">تاريخ الانتهاء</dt>
                        <dd class="col-sm-8">{{ $license->expires_at?->format('Y-m-d') }}</dd>

                        <dt class="col-sm-4">نهاية فترة السماح</dt>
                        <dd class="col-sm-8">{{ $license->graceEndsAt()?->format('Y-m-d') }}</dd>

                        <dt class="col-sm-4">عدد الفروع</dt>
                        <dd class="col-sm-8">{{ $license->max_branches }}</dd>

                        <dt class="col-sm-4">آخر دفعة</dt>
                        <dd class="col-sm-8">{{ $license->last_payment_at?->format('Y-m-d H:i') ?: '—' }}</dd>
                    </dl>

                    @if($license->notes)
                        <hr>
                        <div class="text-muted small mb-1">ملاحظات</div>
                        <div>{{ $license->notes }}</div>
                    @endif
                </div>
                <div class="card-footer d-flex gap-2 flex-wrap">
                    @if($license->status === \App\Models\License::STATUS_ACTIVE)
                        <form method="POST" action="{{ route('admin.licenses.suspend', $license) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-pause-circle"></i> إيقاف
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.licenses.activate', $license) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-play-circle"></i> تفعيل
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-3">سجل الدفعات</h2>
                    @if($license->payments->isEmpty())
                        <p class="text-muted mb-0">لا توجد دفعات مسجلة.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>المدة</th>
                                        <th>المبلغ</th>
                                        <th>الفترة</th>
                                        <th>استلمها</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($license->payments as $payment)
                                        <tr>
                                            <td>{{ $payment->paid_at?->format('Y-m-d') }}</td>
                                            <td>{{ $payment->period_months }} شهور</td>
                                            <td>{{ $payment->amount !== null ? number_format((float) $payment->amount, 2) : '—' }}</td>
                                            <td>{{ $payment->starts_at?->format('Y-m-d') }} → {{ $payment->expires_at?->format('Y-m-d') }}</td>
                                            <td>{{ $payment->receivedBy?->name ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <form method="POST" action="{{ route('admin.licenses.renew', $license) }}" class="card">
                @csrf
                <div class="card-body">
                    <h2 class="h6 mb-3">تجديد نقدي</h2>
                    <div class="mb-3">
                        <label class="form-label">مدة التجديد</label>
                        <select name="period_months" class="form-select @error('period_months') is-invalid @enderror" required>
                            <option value="12" @selected(old('period_months', '12') === '12')>سنة</option>
                            <option value="6" @selected(old('period_months') === '6')>6 شهور</option>
                        </select>
                        @error('period_months') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">المبلغ</label>
                        <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="form-control @error('amount') is-invalid @enderror">
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">تاريخ الدفع</label>
                        <input type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="form-control @error('paid_at') is-invalid @enderror">
                        @error('paid_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">رقم مرجع داخلي</label>
                        <input type="text" name="reference" value="{{ old('reference') }}" class="form-control @error('reference') is-invalid @enderror">
                        @error('reference') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                        @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="card-footer text-start">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cash-coin"></i> تسجيل التجديد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
