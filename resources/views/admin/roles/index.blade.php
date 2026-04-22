@extends('layouts.admin')
@section('title', 'الأدوار')

@section('content')
<x-admin.breadcrumb title="الأدوار والصلاحيات" icon="bi-shield-lock"
    subtitle="تحكم بصلاحيات كل دور وإنشاء أدوار مخصصة" />

<x-admin.data-panel title="الأدوار" :count="$roles->count()" icon="bi-shield-lock">
    <x-slot:actions>
        <a href="{{ route('admin.roles.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> دور جديد
        </a>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الدور</th>
                    <th>الاسم</th>
                    <th>الصلاحيات</th>
                    <th>النوع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($roles as $r)
                    <tr>
                        <td class="fw-bold">{{ $r->label }}</td>
                        <td><code>{{ $r->name }}</code></td>
                        <td><span class="badge bg-primary-transparent">{{ $r->permissions->count() }} صلاحية</span></td>
                        <td>
                            @if($r->is_system)<span class="badge bg-info">نظام</span>
                            @else<span class="badge bg-secondary">مخصص</span>@endif
                        </td>
                        <td>
                            <a href="{{ route('admin.roles.edit', $r) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            @unless($r->is_system)
                                <form action="{{ route('admin.roles.destroy', $r) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف؟')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">
                        <x-admin.empty-state icon="bi-shield-lock" title="ما في أدوار" message="أضف أدواراً مخصصة لفريقك." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endsection
