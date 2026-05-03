@extends('portal.layout')
@section('title', 'الطلب غير متاح')

@section('content')
<div class="pf-section pf-section--centered">
    <i class="bi bi-shop" style="font-size:3rem;color:#9ca3af;"></i>
    <h1 class="pf-h1 mt-3">لا توجد فروع متاحة للطلب حالياً</h1>
    <p class="pf-sub">يبدو أن جميع الفروع مغلقة الآن. حاول لاحقاً أو تواصل مع الإدارة.</p>
    <a href="{{ route('portal.dashboard') }}" class="pf-btn pf-btn--primary mt-3">
        <i class="bi bi-arrow-right"></i> عودة للرئيسية
    </a>
</div>
@endsection
