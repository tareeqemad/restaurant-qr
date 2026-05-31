@extends('layouts.admin')

@section('title', 'قواعد ضريبة المبيعات')

@section('content')
<x-admin.breadcrumb
    title="قواعد ضريبة المبيعات"
    icon="bi-percent"
    subtitle="إعداد مرن للسوق الأمريكي حسب الفرع أو الولاية أو المدينة أو ZIP"
    :crumbs="[['label' => 'تقرير الضريبة', 'url' => route('admin.accounting.tax-report')]]" />

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-light"><strong>قاعدة ضريبية جديدة</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounting.tax-jurisdictions.store') }}">
                    @csrf
                    @if(!\App\Support\BranchContext::current())
                        <div class="mb-3">
                            <label class="form-label fw-bold">الفرع</label>
                            <select name="branch_id" class="form-select">
                                <option value="">كل الفروع / افتراضي</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم القاعدة</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="مثلاً: New York City" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label fw-bold">Country</label>
                            <input type="text" name="country" class="form-control text-uppercase" value="{{ old('country', 'US') }}" maxlength="2" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-bold">State</label>
                            <input type="text" name="state" class="form-control" value="{{ old('state') }}" placeholder="NY">
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-bold">ZIP</label>
                            <input type="text" name="postal_code" class="form-control" value="{{ old('postal_code') }}">
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label class="form-label fw-bold">City</label>
                        <input type="text" name="city" class="form-control" value="{{ old('city') }}">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-bold">النسبة %</label>
                            <input type="number" name="rate" class="form-control" min="0" max="100" step="0.0001" value="{{ old('rate', '0') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">الأولوية</label>
                            <input type="number" name="priority" class="form-control" min="0" value="{{ old('priority', 100) }}">
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <label class="form-label fw-bold">من تاريخ</label>
                            <input type="date" name="effective_from" class="form-control" value="{{ old('effective_from') }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">إلى تاريخ</label>
                            <input type="date" name="effective_to" class="form-control" value="{{ old('effective_to') }}">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input type="checkbox" name="is_default" value="1" id="is_default" class="form-check-input" @checked(old('is_default'))>
                        <label for="is_default" class="form-check-label">قاعدة افتراضية عند عدم وجود تطابق أدق</label>
                    </div>
                    <div class="form-check mt-2">
                        <input type="checkbox" name="is_active" value="1" id="is_active" class="form-check-input" @checked(old('is_active', true))>
                        <label for="is_active" class="form-check-label">فعالة</label>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> حفظ القاعدة</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <x-admin.data-panel title="القواعد" icon="bi-percent" :count="$rules->total()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>القاعدة</th>
                            <th>النطاق</th>
                            <th>النسبة</th>
                            <th>الحالة</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rules as $rule)
                            <tr>
                                <td>
                                    <strong>{{ $rule->name }}</strong>
                                    <span class="text-muted d-block small">{{ $rule->branch?->name ?? 'كل الفروع' }}</span>
                                    @if($rule->notes)
                                        <span class="text-muted d-block small">{{ $rule->notes }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span dir="ltr">{{ $rule->country }} {{ $rule->state }} {{ $rule->city }} {{ $rule->postal_code }}</span>
                                    @if($rule->is_default)
                                        <span class="badge bg-info ms-1">افتراضي</span>
                                    @endif
                                    <span class="text-muted d-block small">أولوية {{ $rule->priority }}</span>
                                </td>
                                <td><strong>{{ number_format((float) $rule->rate, 4) }}%</strong></td>
                                <td>
                                    <span class="badge {{ $rule->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $rule->is_active ? 'فعالة' : 'معطلة' }}</span>
                                    @if($rule->effective_from || $rule->effective_to)
                                        <span class="text-muted d-block small">{{ $rule->effective_from?->toDateString() ?? '...' }} - {{ $rule->effective_to?->toDateString() ?? '...' }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('admin.accounting.tax-jurisdictions.destroy', $rule) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">لا توجد قواعد ضريبة بعد. سيستخدم النظام نسبة الضريبة العامة من الإعدادات.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $rules->links() }}</div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
