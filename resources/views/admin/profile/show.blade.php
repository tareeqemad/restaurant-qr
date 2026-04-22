@extends('layouts.admin')
@section('title', 'الملف الشخصي')

@section('content')
<x-admin.breadcrumb title="الملف الشخصي" icon="bi-person-circle"
    subtitle="تحديث بيانات حسابك وكلمة المرور" />

<form action="{{ route('admin.profile.update') }}" method="POST">
    @csrf @method('PUT')

    <div class="row g-3">

        {{-- ══════════ LEFT: Profile summary card ══════════ --}}
        <div class="col-xl-4">
            <x-admin.data-panel>
                <div class="profile-summary">
                    <div class="profile-avatar-wrap">
                        <img src="{{ $user->avatar_url ?? asset('assets/dashtic/images/faces/1.jpg') }}"
                             class="profile-avatar"
                             alt="{{ $user->name }}">
                        <span class="profile-status-dot" title="متصل الآن"></span>
                    </div>

                    <h4 class="profile-name">{{ $user->name }}</h4>

                    <span class="profile-role-badge">
                        <i class="bi bi-shield-fill-check"></i>
                        {{ $user->role_label ?? $user->role }}
                    </span>

                    <div class="profile-info-list">
                        <div class="profile-info-item">
                            <span class="profile-info-icon"><i class="bi bi-person-badge"></i></span>
                            <div class="profile-info-body">
                                <span class="profile-info-label">اسم المستخدم</span>
                                <span class="profile-info-value"><code>{{ $user->username }}</code></span>
                            </div>
                        </div>
                        <div class="profile-info-item">
                            <span class="profile-info-icon"><i class="bi bi-envelope"></i></span>
                            <div class="profile-info-body">
                                <span class="profile-info-label">البريد الإلكتروني</span>
                                <span class="profile-info-value" dir="ltr">{{ $user->email ?? '—' }}</span>
                            </div>
                        </div>
                        <div class="profile-info-item">
                            <span class="profile-info-icon"><i class="bi bi-telephone"></i></span>
                            <div class="profile-info-body">
                                <span class="profile-info-label">الهاتف</span>
                                <span class="profile-info-value" dir="ltr">{{ $user->phone ?? '—' }}</span>
                            </div>
                        </div>
                        @if($user->station)
                        <div class="profile-info-item">
                            <span class="profile-info-icon"><i class="bi bi-diagram-3"></i></span>
                            <div class="profile-info-body">
                                <span class="profile-info-label">المحطة</span>
                                <span class="profile-info-value">{{ $user->station->name }}</span>
                            </div>
                        </div>
                        @endif
                        <div class="profile-info-item">
                            <span class="profile-info-icon"><i class="bi bi-calendar-check"></i></span>
                            <div class="profile-info-body">
                                <span class="profile-info-label">عضو منذ</span>
                                <span class="profile-info-value">{{ $user->created_at->translatedFormat('j F Y') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </x-admin.data-panel>
        </div>

        {{-- ══════════ RIGHT: Editable fields ══════════ --}}
        <div class="col-xl-8">

            {{-- Personal info --}}
            <x-admin.data-panel title="المعلومات الشخصية" icon="bi-person-gear">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">الاسم الكامل <span class="text-danger">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}"
                               class="form-control @error('name') is-invalid @enderror" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">البريد الإلكتروني</label>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}"
                               class="form-control @error('email') is-invalid @enderror" dir="ltr">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">رقم الهاتف</label>
                        <input type="text" name="phone" value="{{ old('phone', $user->phone) }}"
                               class="form-control @error('phone') is-invalid @enderror" dir="ltr">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">اسم المستخدم</label>
                        <input type="text" value="{{ $user->username }}" class="form-control" disabled>
                        <small class="text-muted">لا يمكن تغيير اسم المستخدم</small>
                    </div>
                </div>
            </x-admin.data-panel>

            {{-- Change password --}}
            <x-admin.data-panel title="تغيير كلمة المرور" icon="bi-shield-lock">
                <div class="alert bg-primary-transparent border-0 mb-3">
                    <i class="bi bi-info-circle-fill"></i>
                    اترك الحقلين فارغين إن لم ترد تغيير كلمة المرور.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">كلمة مرور جديدة</label>
                        <div class="password-field">
                            <input type="password" name="password" id="newPassword"
                                   class="form-control @error('password') is-invalid @enderror"
                                   autocomplete="new-password" minlength="6">
                            <button type="button" class="password-toggle" data-target="#newPassword" title="إظهار/إخفاء">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">6 أحرف على الأقل</small>
                        @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">تأكيد كلمة المرور</label>
                        <div class="password-field">
                            <input type="password" name="password_confirmation" id="newPasswordConfirm"
                                   class="form-control" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-target="#newPasswordConfirm" title="إظهار/إخفاء">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </x-admin.data-panel>

            {{-- Submit --}}
            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('admin.dashboard') }}" class="btn btn-light">
                    <i class="bi bi-x-circle"></i> إلغاء
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> حفظ التغييرات
                </button>
            </div>

        </div>
    </div>
</form>

@push('scripts')
<script>
    // Show/hide password toggles
    document.querySelectorAll('.password-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const input = document.querySelector(this.dataset.target);
            const icon  = this.querySelector('i');
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });
    });
</script>
@endpush
@endsection
