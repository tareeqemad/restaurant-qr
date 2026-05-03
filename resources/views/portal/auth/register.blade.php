@extends('portal.layout')
@section('title', 'إنشاء حساب')

@section('content')
<div class="pf-card" style="max-width: 480px; margin: 40px auto;">
    <h1 class="pf-title">أنشئ حسابك في دقيقة</h1>
    <p class="pf-subtitle">احجز طاولتك، وتابع زياراتك السابقة.</p>

    <form method="POST" action="{{ route('portal.register') }}">
        @csrf

        <div class="pf-input-group">
            <label class="pf-label" for="name">الاسم الكامل</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}"
                   class="pf-input @error('name') has-error @enderror" required autofocus>
            @error('name')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="phone">رقم الهاتف *</label>
            <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                   class="pf-input @error('phone') has-error @enderror" required dir="ltr"
                   placeholder="+970 5X XXX XXXX">
            @error('phone')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="email">البريد الإلكتروني (اختياري)</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}"
                   class="pf-input @error('email') has-error @enderror" dir="ltr">
            @error('email')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        @if($branches->count() > 1)
            <div class="pf-input-group">
                <label class="pf-label" for="default_branch_id">الفرع الأقرب لك</label>
                <select id="default_branch_id" name="default_branch_id" class="pf-input">
                    <option value="">— اختر فرعاً (اختياري) —</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('default_branch_id') == $branch->id)>
                            {{ $branch->name }}{{ $branch->city ? ' — '.$branch->city : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="pf-input-group">
            <label class="pf-label" for="password">كلمة المرور (8 أحرف على الأقل)</label>
            <input type="password" id="password" name="password"
                   class="pf-input @error('password') has-error @enderror" required dir="ltr">
            @error('password')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="password_confirmation">تأكيد كلمة المرور</label>
            <input type="password" id="password_confirmation" name="password_confirmation"
                   class="pf-input" required dir="ltr">
        </div>

        <button class="pf-btn pf-btn--block">
            <i class="bi bi-check2-circle"></i> إنشاء الحساب
        </button>
    </form>

    <p style="text-align:center; margin: 16px 0 0; font-size:.88rem; color: var(--muted);">
        عندك حساب؟ <a href="{{ route('portal.login') }}" class="pf-link-bare">سجّل دخولك</a>
    </p>
</div>
@endsection
