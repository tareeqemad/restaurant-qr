@extends('layouts.admin')

@section('title', 'خرائط الحسابات')

@section('content')
<x-admin.breadcrumb
    title="خرائط الحسابات"
    icon="bi-diagram-3"
    subtitle="وجه قواعد الترحيل وفئات المصاريف وطرق الدفع إلى حسابات محددة من شجرة الحسابات"
    :crumbs="[['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')]]" />

<form method="POST" action="{{ route('admin.accounting.mappings.store') }}">
    @csrf

    <div class="row g-3">
        <div class="col-12">
            <x-admin.data-panel title="قواعد الترحيل المحاسبي" icon="bi-sliders">
                <p class="text-muted small mb-3">
                    هذه القواعد تحدد الحساب الذي يستخدمه النظام تلقائيا لكل جزء من العمليات المالية. عند ترك أي حقل فارغ يستخدم النظام الحساب الافتراضي الظاهر.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th style="width: 28%">الدور المحاسبي</th>
                                <th>الوصف</th>
                                <th style="width: 34%">الحساب المستخدم</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($postingRoleGroups as $group => $roles)
                                <tr class="table-light">
                                    <th colspan="3">{{ $group }}</th>
                                </tr>
                                @foreach($roles as $roleKey => $definition)
                                    @php
                                        $mapping = $mappings->get(\App\Models\AccountMapping::CONTEXT_POSTING_ROLE.'|'.$roleKey);
                                        $defaultAccount = $accountsByCode->get($definition['default']);
                                        $allowedTypes = $definition['types'] ?? [];
                                        $roleAccounts = collect($allowedTypes)
                                            ->flatMap(fn ($type) => $accountsByType->get($type, collect()))
                                            ->sortBy('code')
                                            ->values();
                                        $selectedRoleAccount = old('posting_role_accounts.'.$roleKey, $mapping?->account_id);
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $definition['label'] }}</strong>
                                            <span class="text-muted d-block small">{{ $roleKey }}</span>
                                        </td>
                                        <td class="text-muted small">{{ $definition['description'] }}</td>
                                        <td>
                                            <select name="posting_role_accounts[{{ $roleKey }}]" class="form-select">
                                                <option value="">
                                                    الافتراضي: {{ $definition['default'] }}{{ $defaultAccount ? ' - '.$defaultAccount->name : '' }}
                                                </option>
                                                @foreach($roleAccounts as $account)
                                                    <option value="{{ $account->id }}" @selected((int) $selectedRoleAccount === (int) $account->id)>
                                                        {{ $account->code }} - {{ $account->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin.data-panel>
        </div>

        <div class="col-lg-6">
            <x-admin.data-panel title="فئات المصاريف" icon="bi-receipt">
                <p class="text-muted small mb-3">
                    عند اعتماد مصروف، سيستخدم النظام الحساب المختار هنا بدلا من الحساب العام 5100.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الفئة</th>
                                <th>حساب المصروف</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($expenseCategories as $category)
                                @php
                                    $categoryKey = \App\Models\AccountMapping::keyForLookup($category);
                                    $mapping = $mappings->get(\App\Models\AccountMapping::CONTEXT_EXPENSE_CATEGORY.'|'.$categoryKey);
                                @endphp
                                <tr>
                                    <td class="fw-bold">{{ $category->label }}</td>
                                    <td>
                                        <select name="expense_category_accounts[{{ $category->id }}]" class="form-select">
                                            <option value="">5100 - المصاريف التشغيلية</option>
                                            @foreach($expenseAccounts as $account)
                                                <option value="{{ $account->id }}" @selected((int) old('expense_category_accounts.'.$category->id, $mapping?->account_id) === (int) $account->id)>
                                                    {{ $account->code }} - {{ $account->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin.data-panel>
        </div>

        <div class="col-lg-6">
            <x-admin.data-panel title="طرق الدفع" icon="bi-credit-card-2-front">
                <p class="text-muted small mb-3">
                    عند التحصيل أو الدفع أو الاسترداد، سيستخدم النظام الحساب المختار هنا بدلا من الحسابات العامة 1000/1010.
                </p>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>طريقة الدفع</th>
                                <th>حساب النقد/البنك</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($paymentMethods as $code => $label)
                                @php
                                    $mapping = $mappings->get(\App\Models\AccountMapping::CONTEXT_PAYMENT_METHOD.'|'.$code);
                                    $fallback = $code === 'cash' ? '1000 - الصندوق' : '1010 - البنك';
                                @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $label }}</strong>
                                        <span class="text-muted d-block small">{{ $code }}</span>
                                    </td>
                                    <td>
                                        <select name="payment_method_accounts[{{ $code }}]" class="form-select">
                                            <option value="">{{ $fallback }}</option>
                                            @foreach($paymentAccounts as $account)
                                                <option value="{{ $account->id }}" @selected((int) old('payment_method_accounts.'.$code, $mapping?->account_id) === (int) $account->id)>
                                                    {{ $account->code }} - {{ $account->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-admin.data-panel>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="{{ route('admin.accounting.journal') }}" class="btn btn-light">رجوع</a>
        <button class="btn btn-primary">
            <i class="bi bi-save"></i>
            حفظ الخرائط
        </button>
    </div>
</form>
@endsection
