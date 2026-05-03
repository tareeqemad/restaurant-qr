@extends('portal.layout')
@section('title', 'تسجيل الدخول')

@section('content')
<div class="pf-card" style="max-width: 440px; margin: 40px auto;">
    <h1 class="pf-title">أهلاً بعودتك 👋</h1>
    <p class="pf-subtitle">سجّل دخولك للحجز ومتابعة طلباتك.</p>

    <form method="POST" action="{{ route('portal.login') }}">
        @csrf
        <div class="pf-input-group">
            <label class="pf-label" for="identifier">رقم الهاتف أو البريد الإلكتروني</label>
            <input type="text" id="identifier" name="identifier"
                   value="{{ old('identifier') }}"
                   class="pf-input @error('identifier') has-error @enderror"
                   required autofocus dir="ltr">
            @error('identifier')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="password">كلمة المرور</label>
            <input type="password" id="password" name="password"
                   class="pf-input @error('password') has-error @enderror"
                   required dir="ltr">
            @error('password')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <label class="d-flex align-items-center gap-2 mb-3" style="font-size:.85rem; cursor:pointer;">
            <input type="checkbox" name="remember" value="1" style="accent-color: var(--green-primary);">
            تذكّرني على هذا الجهاز
        </label>

        <button class="pf-btn pf-btn--block">
            <i class="bi bi-box-arrow-in-right"></i> دخول
        </button>
    </form>

    <p style="text-align:center; margin: 16px 0 0; font-size:.88rem; color: var(--muted);">
        ما عندك حساب؟ <a href="{{ route('portal.register') }}" class="pf-link-bare">أنشئ حسابك</a>
    </p>
</div>
@endsection
