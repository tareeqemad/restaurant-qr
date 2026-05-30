@extends('layouts.admin')

@section('title', 'إدارة التراخيص')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">إدارة التراخيص</h1>
            <div class="text-muted small">إنشاء وتجديد تراخيص العملاء المدفوعة نقداً.</div>
        </div>
        <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> ترخيص جديد
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            @if($licenses->isEmpty())
                <p class="text-muted mb-0">لا توجد تراخيص بعد.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>العميل</th>
                                <th>المطعم</th>
                                <th>المفتاح</th>
                                <th>الحالة</th>
                                <th>ينتهي في</th>
                                <th>الخطة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($licenses as $license)
                                <tr>
                                    <td>
                                        <strong>{{ $license->customer_name }}</strong>
                                        @if($license->customer_phone)
                                            <div class="text-muted small">{{ $license->customer_phone }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $license->restaurant_name ?: '—' }}</td>
                                    <td><code>{{ $license->license_key }}</code></td>
                                    <td>
                                        <span class="badge bg-{{ $license->statusColor() }}">
                                            {{ $license->statusLabel() }}
                                        </span>
                                    </td>
                                    <td>{{ $license->expires_at?->format('Y-m-d') }}</td>
                                    <td>{{ $license->period_months }} شهور</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.licenses.show', $license) }}" class="btn btn-sm btn-outline-primary">
                                            عرض
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $licenses->links() }}
            @endif
        </div>
    </div>
</div>
@endsection
