@extends('layouts.admin')
@section('title', 'مسببات الحساسية')

@section('content')
<x-admin.breadcrumb title="مسببات الحساسية" icon="bi-shield-exclamation"
    subtitle="قائمة مسببات الحساسية لتحذير الزبائن عند عرض الأصناف" />

<x-admin.data-panel title="القائمة" :count="$allergens->total()" icon="bi-shield-exclamation">
    <x-slot:actions>
        <a href="{{ route('admin.allergens.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> جديد
        </a>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>أيقونة</th>
                    <th>الرمز</th>
                    <th>الاسم</th>
                    <th>EN</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($allergens as $a)
                    <tr>
                        <td style="font-size:1.5rem">{{ $a->icon }}</td>
                        <td><code>{{ $a->code }}</code></td>
                        <td class="fw-bold">{{ $a->name }}</td>
                        <td>{{ $a->name_en }}</td>
                        <td>
                            <a href="{{ route('admin.allergens.edit', $a) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.allergens.destroy', $a) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">
                        <x-admin.empty-state
                            icon="bi-shield-exclamation"
                            title="ما في مسببات حساسية"
                            message="أضف مسببات الحساسية للتحذير منها في عرض القائمة.">
                            <x-slot:cta>
                                <a href="{{ route('admin.allergens.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> إضافة جديد
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $allergens->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
