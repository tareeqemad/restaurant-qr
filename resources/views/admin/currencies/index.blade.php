@extends('layouts.admin')
@section('title', 'العملات وأسعار الصرف')

@section('content')
<x-admin.breadcrumb title="العملات وأسعار الصرف" icon="bi-currency-exchange"
    subtitle="اضبط أسعار صرف العملات التي يختار منها الزبون — العملة الأساسية محفوظة دائماً كما هي في المحاسبة" />

<div class="alert" style="background:rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
    <i class="bi bi-info-circle-fill"></i>
    <strong>كيف يعمل النظام:</strong>
    العملة الأساسية (<strong>{{ $currencies->firstWhere('is_base')?->code }}</strong>) هي العملة المسجَّلة في كل الفواتير.
    العملات الأخرى تُحوَّل للعرض فقط — لتسهيل القراءة على السيّاح. لا تؤثر على المحاسبة.
</div>

<x-admin.data-panel title="العملات المتاحة" :count="$currencies->count()" icon="bi-coin">
    <form method="POST" action="{{ route('admin.currencies.update-rates') }}">
        @csrf
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الرمز</th>
                        <th>الاسم</th>
                        <th>الرمز المالي</th>
                        <th>سعر الصرف للأساسية</th>
                        <th>مثال</th>
                        <th>نشطة</th>
                        <th>آخر تحديث</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($currencies as $c)
                        @php $base = $currencies->firstWhere('is_base'); @endphp
                        <tr>
                            <td>
                                <strong>{{ $c->code }}</strong>
                                @if($c->is_base)
                                    <span class="badge bg-accent" style="background: rgba(var(--accent-rgb),.15); color: #8a6920;">أساسية</span>
                                @endif
                            </td>
                            <td>{{ $c->name }}</td>
                            <td>{{ $c->symbol }}</td>
                            <td>
                                @if($c->is_base)
                                    <span class="fw-bold">1.000000</span>
                                @else
                                    <div class="input-group input-group-sm" style="max-width:180px;">
                                        <input type="number" step="0.000001" min="0.000001"
                                            name="rates[{{ $c->id }}]" value="{{ $c->rate_to_base }}"
                                            class="form-control">
                                        <span class="input-group-text" style="font-size:.72rem;">1 {{ $c->code }} = </span>
                                    </div>
                                @endif
                            </td>
                            <td class="small text-muted">
                                @if(!$c->is_base && $base)
                                    1 {{ $c->code }} = {{ number_format($c->rate_to_base, 4) }} {{ $base->code }}
                                    <br>
                                    1 {{ $base->code }} ≈ {{ number_format(1 / max((float)$c->rate_to_base, 0.0001), 4) }} {{ $c->code }}
                                @endif
                            </td>
                            <td>
                                @if($c->is_base)
                                    <i class="bi bi-check-circle-fill text-success" title="العملة الأساسية دائماً فعّالة"></i>
                                @else
                                    <input type="hidden" name="active[{{ $c->id }}]" value="0">
                                    <input type="checkbox" name="active[{{ $c->id }}]" value="1"
                                        @checked($c->is_active) class="form-check-input">
                                @endif
                            </td>
                            <td class="small text-muted">{{ $c->rate_updated_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                @if(!$c->is_base)
                                    <form method="POST" action="{{ route('admin.currencies.destroy', $c) }}" class="d-inline" onsubmit="return confirm('حذف هذه العملة؟');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <a href="{{ route('admin.dashboard') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع</a>
            <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ أسعار الصرف</button>
        </div>
    </form>
</x-admin.data-panel>

{{-- Add currency modal --}}
<div class="modal fade" id="addCurrency" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.currencies.store') }}">
                @csrf
                <div class="modal-header">
                    <h5>إضافة عملة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label">الرمز <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" maxlength="3" placeholder="USD" required dir="ltr" style="text-transform: uppercase;">
                        </div>
                        <div class="col-4">
                            <label class="form-label">الاسم <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="دولار أمريكي" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">الرمز المالي <span class="text-danger">*</span></label>
                            <input type="text" name="symbol" class="form-control" placeholder="$" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">سعر الصرف للأساسية <span class="text-danger">*</span></label>
                            <input type="number" step="0.000001" min="0.000001" name="rate_to_base" class="form-control" required placeholder="مثال: 0.71 (يعني 1 دولار = 0.71 دينار)">
                            <small class="text-muted">1 وحدة من هذه العملة = X من العملة الأساسية</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                    <button class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
