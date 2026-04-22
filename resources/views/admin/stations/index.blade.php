@extends('layouts.admin')
@section('title', 'المحطات')

@section('content')
<x-admin.breadcrumb title="محطات الإعداد" icon="bi-diagram-3"
    subtitle="محطات الإنتاج (مطبخ، بار، ...) وتوزيع الطلبات عليها" />

<x-admin.data-panel title="المحطات" :count="$stations->count()" icon="bi-diagram-3">
    <x-slot:actions>
        <a href="{{ route('admin.stations.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> محطة جديدة
        </a>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>أيقونة</th>
                    <th>الكود</th>
                    <th>الاسم</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($stations as $s)
                    <tr>
                        <td>
                            <span style="background:{{ $s->color }}; color:white; padding:.3rem .5rem; border-radius:6px;">
                                <i class="bi {{ $s->icon }}"></i>
                            </span>
                        </td>
                        <td><code>{{ $s->code }}</code></td>
                        <td class="fw-bold">{{ $s->name }}</td>
                        <td>{{ $s->display_order }}</td>
                        <td>
                            @if($s->active)<span class="badge bg-success">نشطة</span>
                            @else<span class="badge bg-secondary">متوقفة</span>@endif
                        </td>
                        <td>
                            <a href="{{ route('admin.stations.edit', $s) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('admin.stations.destroy', $s) }}" method="POST" class="d-inline" onsubmit="return confirm('حذف؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-admin.empty-state
                            icon="bi-diagram-3"
                            title="ما في محطات بعد"
                            message="أضف محطات المطبخ والبار لتوزيع الطلبات عليها تلقائياً.">
                            <x-slot:cta>
                                <a href="{{ route('admin.stations.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> محطة جديدة
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endsection
