<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#122d1e">
    <title>تسجيل الدخول · {{ config('restaurant.name') }}</title>
    <link rel="icon" href="{{ asset('assets/dashtic/images/brand-logos/favicon.ico') }}" type="image/x-icon">
    <link href="{{ asset('assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #1f4733;
            --forest-deep: #122d1e;
            --forest-dark: #0d2317;
            --gold: #d4a550;
            --gold-light: #e8c775;
            --gold-dark: #a37a2b;
        }

        * { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }

        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Tajawal', Arial, sans-serif;
            min-height: 100vh;
            padding: 2rem 1rem 5rem;
            background:
                linear-gradient(135deg, rgba(13,35,23,.85) 0%, rgba(18,45,30,.75) 50%, rgba(31,71,51,.85) 100%),
                url("{{ asset('assets/brand/pattern.svg') }}"),
                linear-gradient(135deg, #0d2317 0%, #1f4733 100%);
            background-size: cover, 240px 240px, cover;
            background-repeat: no-repeat, repeat, no-repeat;
            background-position: center, center, center;
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            position: relative;
            overflow-x: hidden;
        }

        body::before, body::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(212,165,80,.15), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        body::before { top: -100px; right: -100px; }
        body::after { bottom: -100px; left: -100px; background: radial-gradient(circle, rgba(212,165,80,.1), transparent 70%); }

        /* Brand header (outside card) */
        .brand-top {
            text-align: center;
            color: var(--gold);
            animation: drop-in .7s ease;
        }
        .brand-top img { width: 72px; height: 72px; margin-bottom: .25rem; filter: drop-shadow(0 4px 12px rgba(212,165,80,.4)); }
        .brand-top .brand-text {
            font-family: 'Cinzel', serif;
            font-size: 2.4rem;
            font-weight: 600;
            letter-spacing: .08em;
            background: linear-gradient(180deg, #f5deb3 0%, var(--gold) 50%, var(--gold-dark) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1;
        }
        @keyframes drop-in { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

        /* Login card */
        .auth-card {
            background: rgba(255, 253, 247, .98);
            border-radius: 24px;
            padding: 2.25rem 1.75rem 1.5rem;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 30px 80px rgba(0,0,0,.35), 0 0 0 1px rgba(212,165,80,.3);
            position: relative;
            animation: card-rise .8s cubic-bezier(.4, 0, .2, 1);
            backdrop-filter: blur(8px);
        }
        @keyframes card-rise { from { opacity: 0; transform: translateY(30px) scale(.95); } to { opacity: 1; transform: translateY(0) scale(1); } }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0; right: 0; left: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold-light), var(--gold-dark));
            border-radius: 24px 24px 0 0;
        }

        /* Logo inside card */
        .card-logo { text-align: center; margin-bottom: 1.25rem; }
        .card-logo img { width: 56px; height: 56px; }
        .card-logo .card-brand-text {
            font-family: 'Cinzel', serif;
            font-size: 1.8rem;
            font-weight: 600;
            letter-spacing: .08em;
            color: var(--forest);
            line-height: 1;
            margin-top: .25rem;
        }

        .auth-title { color: var(--forest-deep); font-weight: 900; font-size: 1.35rem; text-align: center; margin: .5rem 0 .25rem; letter-spacing: -.01em; }
        .auth-subtitle { color: #78716c; text-align: center; font-size: .85rem; margin: 0 0 1.5rem; font-weight: 500; }

        .auth-input-wrap { position: relative; margin-bottom: .85rem; }
        .auth-input {
            width: 100%;
            background: #fafaf7;
            border: 1.5px solid #e7dfd0;
            border-radius: 99px;
            padding: 14px 22px;
            font-size: .95rem;
            font-weight: 500;
            text-align: right;
            font-family: inherit;
            color: var(--forest-deep);
            transition: all .2s;
            direction: rtl;
        }
        .auth-input::placeholder { color: #a8a29e; }
        .auth-input:focus {
            outline: none;
            border-color: var(--gold);
            background: white;
            box-shadow: 0 0 0 4px rgba(212,165,80,.15);
        }

        .input-icon-left { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--forest); cursor: pointer; font-size: 1.1rem; opacity: .6; transition: opacity .15s; }
        .input-icon-left:hover { opacity: 1; }

        .btn-submit {
            background: linear-gradient(135deg, var(--forest) 0%, var(--forest-deep) 100%);
            color: white;
            border: 0;
            border-radius: 99px;
            padding: 14px 20px;
            font-weight: 800;
            font-size: 1rem;
            width: 100%;
            margin-top: .5rem;
            box-shadow: 0 10px 28px rgba(18,45,30,.35);
            transition: all .2s;
            cursor: pointer;
            font-family: inherit;
            position: relative;
            overflow: hidden;
        }
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0; right: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(212,165,80,.3), transparent);
            transition: right .5s;
        }
        .btn-submit:hover::before { right: 100%; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 14px 36px rgba(18,45,30,.45); }
        .btn-submit:active { transform: translateY(0); }

        .auth-footer { display: flex; justify-content: center; gap: 12px; margin-top: 1.25rem; font-size: .82rem; font-weight: 600; }
        .auth-footer a { color: var(--forest); text-decoration: none; transition: color .15s; }
        .auth-footer a:hover { color: var(--gold-dark); }
        .auth-footer .sep { color: #d4a550; opacity: .5; }

        .demo-creds {
            margin-top: 1.25rem;
            padding: .85rem;
            background: rgba(212,165,80,.08);
            border: 1px dashed var(--gold);
            border-radius: 12px;
            text-align: center;
            font-size: .75rem;
            color: #57534e;
            line-height: 1.6;
        }
        .demo-creds code { background: white; color: var(--forest); padding: 2px 6px; border-radius: 4px; font-weight: 700; }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            padding: .65rem 1rem;
            border-radius: 12px;
            font-size: .85rem;
            margin-bottom: 1rem;
            font-weight: 600;
            text-align: center;
        }

        .copyright {
            position: absolute;
            bottom: 1.2rem;
            left: 0; right: 0;
            color: rgba(255,255,255,.7);
            font-size: .8rem;
            text-align: center;
            font-weight: 500;
            letter-spacing: .03em;
            pointer-events: none;
        }
        .copyright .dot { color: var(--gold); margin: 0 6px; }
    </style>
</head>
<body>

    {{-- Top brand header --}}
    <div class="brand-top">
        <img src="{{ asset('assets/brand/relax-monogram.svg') }}" alt="Relax">
        <div class="brand-text">{{ config('restaurant.name') }}</div>
    </div>

    {{-- Login card --}}
    <div class="auth-card">
        <div class="card-logo">
            <img src="{{ asset('assets/brand/relax-monogram.svg') }}" alt="Relax">
            <div class="card-brand-text">{{ config('restaurant.name') }}</div>
        </div>

        <h2 class="auth-title">أهلاً بعودتك</h2>
        <p class="auth-subtitle">سجّل دخولك للوصول إلى لوحة التحكم</p>

        @if($errors->any())
            <div class="alert-error">
                <i class="bi bi-exclamation-circle-fill me-1"></i> {{ $errors->first() }}
            </div>
        @endif

        <form action="{{ route('login.store') }}" method="POST" autocomplete="on">
            @csrf
            <div class="auth-input-wrap">
                <input type="text" name="username" class="auth-input"
                    placeholder="اسم المستخدم أو البريد الإلكتروني"
                    value="{{ old('username') }}" required autofocus autocomplete="username">
            </div>

            <div class="auth-input-wrap">
                <input type="password" name="password" class="auth-input" id="passwordField"
                    placeholder="كلمة المرور" required autocomplete="current-password">
                <i class="bi bi-eye input-icon-left" id="togglePwd" onclick="togglePassword()"></i>
            </div>

            <label class="d-flex align-items-center gap-2 mb-3 px-2" style="font-size:.85rem; color:#57534e; cursor:pointer;">
                <input type="checkbox" name="remember" value="1" style="accent-color: var(--forest);">
                تذكّرني على هذا الجهاز
            </label>

            <button type="submit" class="btn-submit">
                <i class="bi bi-box-arrow-in-right me-1"></i> تسجيل الدخول
            </button>
        </form>

        <div class="auth-footer">
            <a href="#"><i class="bi bi-question-circle"></i> نسيت كلمة المرور؟</a>
            <span class="sep">|</span>
            <a href="#"><i class="bi bi-headset"></i> تواصل مع الدعم</a>
        </div>

        <div class="demo-creds">
            <i class="bi bi-key-fill" style="color: var(--gold-dark);"></i>
            حسابات تجريبية — كلمة المرور <code>password</code>:<br>
            <code>admin</code> · <code>waiter1</code> · <code>chef1</code> · <code>cashier1</code>
        </div>
    </div>

    {{-- Copyright --}}
    <div class="copyright">
        ©{{ date('Y') }} <span class="dot">•</span> {{ config('restaurant.name') }} Restaurant <span class="dot">•</span> جميع الحقوق محفوظة
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('passwordField');
            const icon = document.getElementById('togglePwd');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye');
            }
        }
    </script>
</body>
</html>
