@extends('layouts.admin')
@section('title', 'الموظفون')

@section('content')
<x-admin.breadcrumb title="الموظفون" icon="bi-people"
    subtitle="إدارة فريق العمل والأدوار والصلاحيات" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الموظفين', 'value' => $stats['total'],    'icon' => 'bi-people-fill',        'color' => 'primary'],
    ['label' => 'فعّالون',          'value' => $stats['active'],   'icon' => 'bi-check-circle-fill', 'color' => 'success'],
    ['label' => 'المدراء',          'value' => $stats['admins'],   'icon' => 'bi-shield-lock-fill',  'color' => 'accent'],
    ['label' => 'غير فعّالين',      'value' => $stats['inactive'], 'icon' => 'bi-x-circle',          'color' => 'muted'],
]" />

<x-admin.data-panel title="قائمة الموظفين" :count="$users->total()" icon="bi-people">
    <x-slot:actions>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> موظف جديد
        </a>
        @if(request()->hasAny(['search', 'role', 'status']))
            <a href="{{ route('admin.users.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-4">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 الاسم/المستخدم/البريد">
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select">
                    <option value="">كل الأدوار</option>
                    @foreach($roles as $v => $label)
                        <option value="{{ $v }}" @selected(request('role')===$v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="active"    @selected(request('status')==='active')>فعال</option>
                    <option value="inactive"  @selected(request('status')==='inactive')>غير فعال</option>
                    <option value="suspended" @selected(request('status')==='suspended')>معطل</option>
                </select>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-funnel"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    @php $showBranchCol = (bool) session('view_all_branches'); @endphp
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الاسم</th>
                    <th>المستخدم</th>
                    <th>الدور</th>
                    @if($showBranchCol)<th>الفروع</th>@endif
                    <th>المحطة</th>
                    <th>الهاتف</th>
                    <th>الحالة</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $u)
                    <tr>
                        <td class="fw-bold">{{ $u->name }}</td>
                        <td><code>{{ $u->username }}</code></td>
                        <td><span class="badge bg-primary-transparent">{{ $u->role_label }}</span></td>
                        @if($showBranchCol)
                            <td>
                                @forelse($u->branches as $b)
                                    <x-admin.branch-tag :branch="$b" />
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                        @endif
                        <td>{{ $u->station?->name ?? '—' }}</td>
                        <td>{{ $u->phone ?? '—' }}</td>
                        <td>
                            @if($u->status==='active')<span class="badge bg-success">فعال</span>
                            @elseif($u->status==='suspended')<span class="badge bg-danger">معطل</span>
                            @else<span class="badge bg-secondary">غير فعال</span>@endif
                        </td>
                        <td>
                            <a href="{{ route('admin.users.edit', $u) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.users.toggle-status', $u) }}" method="POST" class="d-inline">@csrf @method('PATCH')
                                <button class="btn btn-sm btn-light"><i class="bi bi-toggle-on"></i></button>
                            </form>
                            <form action="{{ route('admin.users.destroy', $u) }}" method="POST" class="d-inline" onsubmit="return confirm('تأكيد الحذف؟')">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-admin.empty-state
                            icon="bi-people"
                            title="ما في موظفين بعد"
                            message="أضف فريق عملك وحدد الأدوار لكل منهم.">
                            <x-slot:cta>
                                <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> أضف موظفاً
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $users->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
