@extends('layouts.admin')
@section('title', 'الأدوار')

@section('content')
<x-admin.breadcrumb title="الأدوار والصلاحيات" icon="bi-shield-lock"
    subtitle="القوالب العامة يديرها Super Admin؛ كل فرع يستخدمها كما هي أو يستنسخها للتخصيص" />

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
                    <th>النطاق</th>
                    <th>الصلاحيات</th>
                    <th>النوع</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($roles as $r)
                    <tr>
                        <td class="fw-bold">{{ $r->label }}</td>
                        <td><code>{{ $r->name }}</code></td>
                        <td>
                            @if($r->isGlobal())
                                <span class="badge bg-info-transparent">
                                    <i class="bi bi-globe"></i> قالب عام
                                </span>
                            @else
                                <span class="badge bg-primary-transparent">
                                    <i class="bi bi-buildings"></i> {{ $r->branch?->name ?? '—' }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-secondary-transparent">
                                {{ $r->permissions->count() }} صلاحية
                            </span>
                        </td>
                        <td>
                            @if($r->is_system)
                                <span class="badge bg-info">نظام</span>
                            @else
                                <span class="badge bg-secondary">مخصَّص</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.roles.edit', $r) }}"
                               class="btn btn-sm btn-light" title="تعديل">
                                <i class="bi bi-pencil"></i>
                            </a>

                            @if($r->isGlobal() && ! auth()->user()->isOwnerLevel())
                                <form action="{{ route('admin.roles.clone', $r) }}"
                                      method="POST" class="d-inline"
                                      title="استنسخ هذا القالب لفرعي لتخصيصه">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="return confirm('استنسخ «{{ $r->label }}» في فرعك الحالي؟');">
                                        <i class="bi bi-files"></i>
                                    </button>
                                </form>
                            @endif

                            @unless($r->is_system)
                                <form action="{{ route('admin.roles.destroy', $r) }}"
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('حذف هذا الدور؟');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-admin.empty-state icon="bi-shield-lock"
                            title="لا توجد أدوار متاحة"
                            message="أنشئ دوراً مخصصاً أو استنسخ قالباً عاماً." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endsection
