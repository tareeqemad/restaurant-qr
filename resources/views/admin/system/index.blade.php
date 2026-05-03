@extends('layouts.admin')
@section('title', 'إدارة النظام')

@section('content')
<x-admin.breadcrumb
    title="إدارة النظام"
    icon="bi-gear-fill"
    subtitle="إعدادات على مستوى المنصّة — متاحة لمدير النظام فقط." />

<div class="row g-3">
    {{-- ═════════════ الإجراء الرئيسي: إعادة تعيين البيانات التجريبية ═════════════ --}}
    <div class="col-xl-8">
        <x-admin.data-panel title="إعادة تعيين البيانات التجريبية" icon="bi-arrow-counterclockwise">

            @if(! $hasDemoData)
                <x-admin.info-box type="success" icon="bi-check-circle-fill">
                    لا توجد بيانات تجريبية. النظام جاهز للإنتاج.
                </x-admin.info-box>
            @else
                <p class="text-muted mb-3">
                    يحذف هذا الإجراء كل بيانات الفروع، الموظفين، القوائم، الطاولات، الطلبات،
                    الفواتير، الحجوزات، التقييمات، المخزون، والشفتات.
                    <br>
                    <strong class="text-dark">الهدف: تنظيف بيانات العرض قبل تسليم النظام للعميل.</strong>
                </p>

                <div class="row g-3 mb-3">
                    {{-- ما يبقى --}}
                    <div class="col-md-6">
                        <x-admin.info-box type="warning" icon="bi-shield-fill-check">
                            <strong class="d-block mb-2">ما يبقى آمناً</strong>
                            <ul class="mb-0 ps-3" style="font-size: .82rem;">
                                <li>حسابك الحالي ({{ auth()->user()->name }} — {{ auth()->user()->username }})</li>
                                <li>الأدوار والصلاحيات</li>
                                <li>الوحدات (g, ml, kg، …) ومسببات الحساسية</li>
                                <li>الموردين والمكوّنات (المخزون المركزي)</li>
                                <li>إعدادات النظام والعملات</li>
                            </ul>
                        </x-admin.info-box>
                    </div>

                    {{-- ما يحذف --}}
                    <div class="col-md-6">
                        <x-admin.info-box type="danger" icon="bi-exclamation-triangle-fill">
                            <strong class="d-block mb-2">ما يُحذف نهائياً</strong>
                            <ul class="mb-0 ps-3" style="font-size: .82rem;">
                                <li>كل الفروع وما يتبعها (محطات، طاولات، مناطق، قوائم، أصناف)</li>
                                <li>كل الموظفين باستثناء حسابك</li>
                                <li>كل الطلبات، الفواتير، الدفعات، المرتجعات</li>
                                <li>كل الشفتات، الحضور، الحجوزات، التقييمات</li>
                                <li>كل حركات المخزون، أوامر الشراء، فواتير الموردين</li>
                                <li>كل سجل النشاط</li>
                            </ul>
                        </x-admin.info-box>
                    </div>
                </div>

                <form action="{{ route('admin.system.reset-demo') }}" method="POST"
                      onsubmit="return confirm('متأكد فعلاً؟ هذا الإجراء لا يمكن التراجع عنه.');"
                      class="border-top pt-3">
                    @csrf
                    <label class="form-label fw-bold mb-2">
                        للتأكيد، اكتب الكلمة:
                        <code class="px-2 py-1 bg-danger-transparent text-danger rounded">احذف-البيانات</code>
                    </label>
                    <div class="d-flex gap-2 align-items-start flex-wrap">
                        <input type="text" name="confirmation"
                               class="form-control @error('confirmation') is-invalid @enderror"
                               style="max-width: 320px;"
                               required autocomplete="off"
                               placeholder="احذف-البيانات">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash3-fill"></i>
                            مسح البيانات وإعادة التعيين
                        </button>
                    </div>
                    @error('confirmation')
                        <div class="text-danger small mt-2">
                            <i class="bi bi-exclamation-circle"></i> {{ $message }}
                        </div>
                    @enderror
                </form>
            @endif

        </x-admin.data-panel>
    </div>

    {{-- ═════════════ خطوات ما بعد التعيين ═════════════ --}}
    <div class="col-xl-4">
        <x-admin.data-panel title="خطوات بداية الإنتاج" icon="bi-list-check">
            <p class="text-muted small mb-3">
                بعد إعادة التعيين، اتبع هذه الخطوات لإطلاق النظام لعميلك:
            </p>

            <ol class="syssteps mb-0">
                <li><strong>أنشئ أول فرع</strong> من قائمة «الفروع»</li>
                <li><strong>أضف محطات</strong> الفرع (مطبخ، بار، …)</li>
                <li><strong>أضف الطاولات</strong> + المناطق (داخلي/خارجي)</li>
                <li><strong>أضف التصنيفات</strong> ثم أصناف القائمة</li>
                <li><strong>أنشئ حسابات الموظفين</strong> (مدير، كاشير، شيف، جرسون)</li>
                <li><span class="text-muted">(اختياري)</span> أضف فروع إضافية وانسخ القائمة بضغطة زر</li>
            </ol>
        </x-admin.data-panel>
    </div>
</div>

@push('styles')
<style>
    /* Numbered steps with brand-accent circle markers — matches the
       breadcrumb / KPI accents elsewhere in the admin. */
    .syssteps {
        list-style: none;
        counter-reset: step;
        padding: 0;
        margin: 0;
    }
    .syssteps li {
        counter-increment: step;
        position: relative;
        padding: 8px 38px 8px 0;
        font-size: .9rem;
        color: #374151;
        border-bottom: 1px solid #f3f4f6;
        line-height: 1.5;
    }
    .syssteps li:last-child { border-bottom: 0; }
    .syssteps li::before {
        content: counter(step);
        position: absolute;
        right: 0;
        top: 8px;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: rgba(var(--accent-rgb), .14);
        color: var(--accent, #b8872a);
        font-weight: 800;
        font-size: .82rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    /* LTR fallback (RTL is the default for the admin shell) */
    [dir="ltr"] .syssteps li {
        padding: 8px 0 8px 38px;
    }
    [dir="ltr"] .syssteps li::before {
        right: auto;
        left: 0;
    }
</style>
@endpush
@endsection
