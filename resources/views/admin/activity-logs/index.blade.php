@extends('layouts.admin')
@section('title', 'سجل النشاطات')

@section('content')
<x-admin.breadcrumb title="سجل النشاطات" icon="bi-clock-history"
    subtitle="كل أحداث النظام: من فعل ماذا ومتى" />

<x-admin.data-panel title="سجل الأحداث" :count="$logs->total()" icon="bi-clock-history">
    <x-slot:actions>
        @if(request()->hasAny(['event', 'date']))
            <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-6">
                <input name="event" value="{{ request('event') }}" class="form-control" placeholder="🔍 نوع الحدث (مثلاً: order.approved)">
            </div>
            <div class="col-md-3"><input type="date" name="date" value="{{ request('date') }}" class="form-control"></div>
            <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button></div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الوقت</th>
                    <th>الحدث</th>
                    <th>الوصف</th>
                    <th>المستخدم</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td><small>{{ $log->created_at->format('Y-m-d H:i:s') }}</small></td>
                        <td><code>{{ $log->event }}</code></td>
                        <td>{{ $log->description }}</td>
                        <td>{{ $log->causer?->name ?? '—' }}</td>
                        <td><small class="text-muted">{{ $log->ip_address }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="5">
                        <x-admin.empty-state
                            icon="bi-clock-history"
                            title="ما في سجلات"
                            message="لم تُسجل أي أحداث ضمن معايير البحث الحالية." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
