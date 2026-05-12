@extends('layouts.admin')
@section('title', 'العروض والإعلانات')

@section('content')
<x-admin.breadcrumb
    title="العروض والإعلانات"
    icon="bi-megaphone-fill"
    subtitle="حملات تسويقية وعروض موجَّهة لزبائن البوابة" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الحملات',  'value' => $stats['total'],     'icon' => 'bi-collection',         'color' => 'primary'],
    ['label' => 'منشورة الآن',     'value' => $stats['published'], 'icon' => 'bi-broadcast-pin',      'color' => 'success'],
    ['label' => 'مسودات',          'value' => $stats['draft'],     'icon' => 'bi-file-earmark-text',  'color' => 'warning'],
    ['label' => 'منتهية / مؤرشفة', 'value' => $stats['expired'],   'icon' => 'bi-archive',            'color' => 'muted'],
]" />

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold">بحث في العنوان أو النص</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="مثلاً: خصم رمضان">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">الحالة</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <option value="draft"     @selected(request('status')==='draft')>مسودة</option>
                    <option value="published" @selected(request('status')==='published')>منشورة</option>
                    <option value="expired"   @selected(request('status')==='expired')>منتهية</option>
                    <option value="archived"  @selected(request('status')==='archived')>مؤرشفة</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i> استعلام</button>
            </div>
            <div class="col-md-3 text-end">
                @can('create', App\Models\Announcement::class)
                    <a href="{{ route('admin.announcements.create') }}" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-lg"></i> إعلان جديد
                    </a>
                @endcan
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        @if($announcements->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-megaphone" style="font-size: 2.4rem; opacity: .4;"></i>
                <p class="mb-2 mt-2">لا توجد إعلانات بعد.</p>
                @can('create', App\Models\Announcement::class)
                    <a href="{{ route('admin.announcements.create') }}" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-plus-lg"></i> أنشئ أول إعلان
                    </a>
                @endcan
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>العنوان</th>
                            <th>الجمهور</th>
                            <th class="text-center">المرسل إليهم</th>
                            <th>الفترة</th>
                            <th>الحالة</th>
                            <th class="text-end">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($announcements as $a)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="ann-icon" style="background:{{ $a->color ?: '#F9A825' }}20; color:{{ $a->color ?: '#F9A825' }};">
                                            <i class="bi {{ $a->icon ?: 'bi-megaphone-fill' }}"></i>
                                        </span>
                                        <div>
                                            <strong>{{ $a->title }}</strong>
                                            <small class="d-block text-muted" style="font-size:.78rem;">
                                                {{ \Illuminate\Support\Str::limit($a->body, 60) }}
                                            </small>
                                        </div>
                                    </div>
                                </td>
                                <td><small>{{ $a->audienceLabel() }}</small></td>
                                <td class="text-center">
                                    {{-- Guests channel doesn't fan out as notifications, so a
                                         "0 مستلم" number is misleading. Show the channel label
                                         instead so the admin sees this is a banner, not a push. --}}
                                    @if($a->isGuestsAudience())
                                        <span class="badge bg-light text-dark border" title="بانر يظهر للزبائن الغير مسجَّلين على شاشة المنيو">
                                            <i class="bi bi-megaphone"></i> بانر منيو
                                        </span>
                                    @elseif($a->isPublished() || $a->status === 'expired' || $a->status === 'archived')
                                        <strong>{{ number_format($a->recipients_count) }}</strong>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($a->starts_at || $a->ends_at)
                                        <small class="d-block">
                                            من: {{ $a->starts_at?->format('Y-m-d') ?? '—' }}<br>
                                            إلى: {{ $a->ends_at?->format('Y-m-d') ?? '—' }}
                                        </small>
                                    @else
                                        <small class="text-muted">دائم</small>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ $a->statusColor() }}">
                                        {{ $a->statusLabel() }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.announcements.edit', $a) }}" class="btn btn-sm btn-light" title="تعديل">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    @if($a->status === 'draft')
                                        @can('publish', $a)
                                            <form action="{{ route('admin.announcements.publish', $a) }}" method="POST" class="d-inline"
                                                  onsubmit="return confirm('سيتم إرسال الإعلان لجميع الزبائن المستهدفين الآن. متابعة؟');">
                                                @csrf
                                                <button class="btn btn-sm btn-success" title="نشر">
                                                    <i class="bi bi-broadcast"></i> نشر
                                                </button>
                                            </form>
                                        @endcan
                                    @elseif($a->status === 'published')
                                        @can('publish', $a)
                                            <form action="{{ route('admin.announcements.unpublish', $a) }}" method="POST" class="d-inline"
                                                  onsubmit="return confirm('إلغاء نشر الإعلان؟ الإشعارات المُرسلة سابقاً تبقى عند الزبائن.');">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-warning" title="إلغاء النشر">
                                                    <i class="bi bi-stop-circle"></i> أرشفة
                                                </button>
                                            </form>
                                        @endcan
                                    @endif
                                    @can('delete', $a)
                                        <form action="{{ route('admin.announcements.destroy', $a) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm('حذف الإعلان نهائياً؟');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="حذف">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $announcements->links() }}</div>
        @endif
    </div>
</div>

<style>
.ann-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
</style>
@endsection
