@extends('layouts.admin')

@section('title', 'المزامنة مع السحابة')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0">المزامنة مع السحابة</h1>

        <form method="POST" action="{{ route('admin.sync.run') }}">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-repeat"></i> مزامنة الآن
            </button>
        </form>
    </div>

    @if (session('success'))
        <div class="alert alert-info">{{ session('success') }}</div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 text-muted mb-3">إعدادات هذا الجهاز</h2>
            <dl class="row mb-0">
                <dt class="col-sm-3">الدور</dt>
                <dd class="col-sm-9">
                    @switch($role)
                        @case('branch') فرع (يزامن مع السحابة) @break
                        @case('cloud') سحابة (يستقبل من الفروع) @break
                        @default مستقل (المزامنة متوقفة)
                    @endswitch
                </dd>

                <dt class="col-sm-3">الحالة</dt>
                <dd class="col-sm-9">
                    @if ($enabled)
                        <span class="badge bg-success">مفعّلة</span>
                    @else
                        <span class="badge bg-secondary">متوقفة</span>
                    @endif
                </dd>

                <dt class="col-sm-3">عنوان السحابة</dt>
                <dd class="col-sm-9">{{ $cloudUrl ?: '—' }}</dd>
            </dl>

            <p class="text-muted small mt-3 mb-0">
                المزامنة تتم تلقائياً كل دقيقة؛ أول ما يرجع الإنترنت تُرفع التغييرات المعلّقة
                لحالها. زر «مزامنة الآن» للاستعجال فقط.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6 text-muted mb-3">حالة المسارات</h2>

            @if ($states->isEmpty())
                <p class="text-muted mb-0">لم تتم أي مزامنة بعد.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>المسار</th>
                                <th>الاتجاه</th>
                                <th>آخر حالة</th>
                                <th>عدد الصفوف</th>
                                <th>آخر مزامنة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($states as $state)
                                <tr>
                                    <td>{{ $state->stream }}</td>
                                    <td>{{ $state->direction === 'down' ? 'تنزيل ⬇️' : 'رفع ⬆️' }}</td>
                                    <td>
                                        @switch($state->last_status)
                                            @case('ok')
                                                <span class="badge bg-success">ناجحة</span> @break
                                            @case('offline')
                                                <span class="badge bg-warning text-dark">غير متصل</span> @break
                                            @case('error')
                                                <span class="badge bg-danger" title="{{ $state->last_error }}">خطأ</span> @break
                                            @default
                                                <span class="badge bg-secondary">قيد الانتظار</span>
                                        @endswitch
                                    </td>
                                    <td>{{ $state->last_count }}</td>
                                    <td>{{ $state->last_synced_at?->diffForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
