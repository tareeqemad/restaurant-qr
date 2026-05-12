<!DOCTYPE html>
{{--
    Customer portal login. Visual structure intentionally mirrors
    `auth/login.blade.php` (the staff login) so both surfaces feel like the
    same brand. Only customer-specific bits are different:
        - field is `identifier` (phone OR email), not just `username`
        - form posts to `portal.login`
        - copy + a registration CTA replace the staff "system access" wording
    Keep the two pages visually in sync; if the staff login gets a polish,
    bring it across here too.
--}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1B5E20">
    <title>تسجيل الدخول · {{ config('restaurant.name') }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-primary: #2E7D32;
            --green-dark:    #1B5E20;
            --green-soft:    #43A047;
            --green-mint:    #C8E6C9;
            --gold:          #F9A825;
            --ink:           #1F2937;
            --ink-2:         #374151;
            --muted:         #6B7280;
            --muted-2:       #9CA3AF;
            --line:          #E5E7EB;
            --cream:         #FAF7F2;
            --paper:         #FFFFFF;
            --danger-bg:     #FEF2F2;
            --danger-border: #FECACA;
            --danger-fg:     #991B1B;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body {
            font-family: 'Tajawal', system-ui, sans-serif;
            color: var(--ink);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(circle at 80% 10%, rgba(67,160,71,.10), transparent 55%),
                radial-gradient(circle at 10% 90%, rgba(200,230,201,.30), transparent 55%),
                linear-gradient(135deg, #FFFFFF 0%, #FAF7F2 100%);
            min-height: 100vh;
            overflow: hidden;
            position: relative;
        }

        /* ─── Animated background blobs ─────────────────────────────── */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            pointer-events: none;
            z-index: 0;
            will-change: transform;
            animation: blobFloat 18s ease-in-out infinite;
        }
        .blob-1 { top: -120px; right: -80px;  width: 480px; height: 480px; background: rgba(67,160,71,.22); animation-delay: 0s; }
        .blob-2 { bottom: -160px; left: -60px; width: 520px; height: 520px; background: rgba(46,125,50,.16); animation-delay: -6s; }
        .blob-3 { top: 35%; left: 40%;        width: 420px; height: 420px; background: rgba(200,230,201,.28); animation-delay: -12s; }

        /* Dotted grid overlay */
        .dot-grid {
            position: fixed;
            inset: 0;
            background-image: radial-gradient(rgba(46,125,50,.12) 1px, transparent 1px);
            background-size: 22px 22px;
            -webkit-mask-image: radial-gradient(ellipse at center, black 25%, transparent 75%);
            mask-image: radial-gradient(ellipse at center, black 25%, transparent 75%);
            pointer-events: none;
            z-index: 0;
        }

        /* ─── Page grid ─────────────────────────────────────────────── */
        .page {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr;
            min-height: 100vh;
        }
        @media (min-width: 992px) {
            .page {
                height: 100vh;
                /* RTL: col-1 (52%) → RIGHT (hero), col-2 (48%) → LEFT (form) */
                grid-template-columns: 52% 48%;
            }
        }

        /* ─── Hero pane (right in RTL) ──────────────────────────────── */
        .hero {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3.5rem 4rem 4rem;
            overflow: hidden;
        }
        @media (max-width: 991px) { .hero { display: none; } }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .55rem 1rem;
            border-radius: 99px;
            background: rgba(46,125,50,.10);
            border: 1px solid rgba(46,125,50,.25);
            color: var(--green-dark);
            font-size: .82rem; font-weight: 700;
            width: fit-content;
            margin-bottom: 1.5rem;
            opacity: 0;
            animation: fadeUp .7s ease-out .15s forwards;
        }

        .hero-title {
            font-size: clamp(2.4rem, 4.2vw, 3.4rem);
            font-weight: 900;
            line-height: 1.18;
            letter-spacing: -.02em;
            margin: 0 0 1.25rem;
            color: var(--ink);
            opacity: 0;
            animation: fadeUp .7s ease-out .25s forwards;
        }
        .hero-title .green { color: var(--green-primary); }
        .hero-title .gold  { color: var(--gold); }

        .hero-desc {
            font-size: 1rem;
            color: var(--muted);
            line-height: 1.85;
            max-width: 520px;
            margin: 0 0 2.25rem;
            font-weight: 500;
            opacity: 0;
            animation: fadeUp .7s ease-out .35s forwards;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            max-width: 540px;
            opacity: 0;
            animation: fadeUp .7s ease-out .45s forwards;
        }
        .feature {
            background: rgba(255,255,255,.70);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(229,231,235,.8);
            border-radius: 18px;
            padding: 1.1rem .9rem;
            text-align: center;
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .feature:hover {
            transform: translateY(-4px);
            border-color: rgba(46,125,50,.3);
            box-shadow: 0 14px 30px rgba(46,125,50,.12);
        }
        .feature-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: rgba(46,125,50,.14);
            color: var(--green-primary);
            display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 1.25rem;
            margin: 0 auto .55rem;
        }
        .feature-title { font-size: .88rem; font-weight: 800; color: var(--ink); }

        .hero-foot {
            position: absolute;
            bottom: 1.5rem;
            right: 4rem; left: 4rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .78rem;
            color: var(--muted-2);
            font-weight: 500;
        }

        /* ─── Form pane (left in RTL) ───────────────────────────────── */
        .form-pane {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.5rem;
        }

        .card-shell {
            position: relative;
            width: 100%;
            max-width: 420px;
            opacity: 0;
            animation: fadeUp .7s ease-out .20s forwards;
        }
        .card-glow {
            position: absolute;
            inset: -20px;
            background: radial-gradient(circle, rgba(46,125,50,.35), transparent 65%);
            filter: blur(28px);
            border-radius: 32px;
            z-index: -1;
            opacity: .5;
        }

        .auth-card {
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,.6);
            border-radius: 24px;
            padding: 2rem;
            box-shadow:
                0 30px 60px -20px rgba(31,41,55,.18),
                0 12px 28px -12px rgba(46,125,50,.10);
        }

        .auth-title {
            font-size: 1.5rem; font-weight: 800;
            color: var(--green-dark);
            margin: 0 0 .35rem;
            text-align: center;
            letter-spacing: -.01em;
        }
        .auth-subtitle {
            font-size: .9rem; color: var(--muted);
            text-align: center;
            margin: 0 0 1.5rem;
            font-weight: 500;
        }
        .auth-logo {
            display: block;
            width: 144px; height: 144px;
            margin: 0 auto 1.5rem;
            object-fit: contain;
            filter: drop-shadow(0 12px 24px rgba(46,125,50,.18));
            /* Visual-only scale — keeps the card layout untouched */
            transform: scale(1.4);
            transform-origin: center;
        }

        /* Inputs */
        .field { margin-bottom: 1rem; }
        .field-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--ink-2);
            letter-spacing: .04em;
            margin-bottom: .4rem;
            padding-inline-start: .15rem;
        }
        .input-wrap { position: relative; }
        .input-control {
            width: 100%;
            background: var(--cream);
            border: 1.5px solid var(--line);
            border-radius: 16px;
            padding: 14px 48px 14px 48px;
            font-size: .95rem; font-weight: 500;
            color: var(--ink);
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .input-control[dir="ltr"] { text-align: left; }
        .input-control::placeholder { color: var(--muted-2); font-weight: 500; }
        .input-control:focus {
            outline: none;
            background: var(--paper);
            border-color: rgba(46,125,50,.9);
            box-shadow: 0 0 0 4px rgba(46,125,50,.14);
        }
        [x-cloak] { display: none !important; }
        .icon-leading,
        .icon-trailing {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: var(--green-primary);
            font-size: 1.05rem;
            opacity: .85;
        }
        .icon-leading  { right: 16px; pointer-events: none; }
        .icon-trailing {
            left: 16px;
            cursor: pointer;
            transition: color .15s, opacity .15s;
            width: 28px;
            height: 28px;
            border: 0;
            padding: 0;
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: inherit;
        }
        .icon-trailing:hover { color: var(--green-dark); opacity: 1; }

        .caps-warn {
            display: none;
            margin-top: .5rem;
            padding: .45rem .7rem;
            background: rgba(249,168,37,.10);
            border: 1px solid rgba(249,168,37,.35);
            border-radius: 10px;
            color: #92400E;
            font-size: .78rem; font-weight: 600;
        }
        .caps-warn.is-on { display: flex; align-items: center; gap: .4rem; }
        .caps-warn i { color: var(--gold); }

        /* Submit button */
        .btn-submit {
            position: relative;
            width: 100%;
            background: linear-gradient(135deg, var(--green-primary) 0%, var(--green-dark) 100%);
            color: #fff;
            border: 0;
            border-radius: 16px;
            padding: 14px 18px;
            font-weight: 800; font-size: 1rem;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center; justify-content: center;
            gap: .5rem;
            margin-top: .5rem;
            box-shadow:
                0 12px 28px -8px rgba(46,125,50,.50),
                inset 0 1px 0 rgba(255,255,255,.25),
                inset 0 -2px 0 rgba(249,168,37,.40);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
            overflow: hidden;
        }
        .btn-submit::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,.32) 50%, transparent 65%);
            transform: translateX(-100%);
            animation: shimmer 2.8s ease-in-out infinite;
        }
        .btn-submit:hover { transform: translateY(-2px); filter: brightness(1.05); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled,
        .btn-submit.is-loading { opacity: .6; pointer-events: none; }
        .btn-submit .spinner { display: none; }
        .btn-submit.is-loading .label,
        .btn-submit.is-loading .icon-fwd { display: none; }
        .btn-submit.is-loading .spinner { display: inline-flex; }
        .btn-submit .spinner i { animation: spin 1s linear infinite; }

        .forgot-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: var(--green-primary);
            font-size: .9rem; font-weight: 700;
            text-decoration: none;
            background: none; border: 0; cursor: pointer;
            font-family: inherit;
            width: 100%;
        }
        .forgot-link:hover { color: var(--green-dark); text-decoration: underline; }

        /* Sign-up link — separates new visitors from existing customers
           who just forgot their password. Kept directly below the
           "forgot password" button so both options sit in one spot. */
        .signup-row {
            display: flex;
            justify-content: center;
            gap: .35rem;
            margin-top: .85rem;
            padding-top: .85rem;
            border-top: 1px dashed var(--line);
            font-size: .88rem;
            color: var(--muted);
            font-weight: 500;
        }
        .signup-row a {
            color: var(--green-dark);
            font-weight: 800;
            text-decoration: none;
            position: relative;
        }
        .signup-row a:hover { text-decoration: underline; }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .7rem .9rem;
            border-radius: 12px;
            font-size: .85rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-fg);
            animation: shake .4s ease;
        }
        .alert-success {
            background: #ECFDF5;
            border: 1px solid #A7F3D0;
            color: #065F46;
        }

        /* Forgot password panel */
        .forgot-panel { display: none; }
        .forgot-panel.is-open { display: block; animation: fadeUp .4s ease-out forwards; }
        .login-panel.is-hidden { display: none; }
        .forgot-help {
            font-size: .85rem;
            color: var(--muted);
            text-align: center;
            margin: 0 0 1.25rem;
            line-height: 1.7;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            margin-top: 1rem;
            color: var(--green-primary);
            background: none; border: 0; cursor: pointer;
            font-family: inherit;
            font-size: .9rem; font-weight: 700;
            padding: 0;
        }
        .back-link:hover { color: var(--green-dark); }

        .form-foot {
            text-align: center;
            margin-top: 1.25rem;
            font-size: .78rem;
            color: var(--muted-2);
            font-weight: 500;
        }

        /* ─── Animations ────────────────────────────────────────────── */
        @keyframes blobFloat {
            0%   { transform: translate(0, 0) scale(1); }
            33%  { transform: translate(30px, -40px) scale(1.08); }
            66%  { transform: translate(-25px, 30px) scale(.94); }
            100% { transform: translate(0, 0) scale(1); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes shimmer {
            0%, 12%  { transform: translateX(-100%); }
            55%      { transform: translateX(100%); }
            100%     { transform: translateX(100%); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%      { transform: translateX(-5px); }
            40%      { transform: translateX(5px); }
            60%      { transform: translateX(-4px); }
            80%      { transform: translateX(3px); }
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }

        /* ─── Mobile tweaks ─────────────────────────────────────────── */
        @media (max-width: 991px) {
            html, body { overflow: auto; height: auto; }
            .form-pane { padding: 2rem 1rem 3rem; min-height: 100vh; }
            .auth-card { padding: 1.6rem; }
        }
        @media (max-width: 480px) {
            .auth-logo { width: 120px; height: 120px; }
        }
    </style>
    @include('partials.runtime-theme')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>
</head>
<body x-data="{ showPassword: false, capsLock: false, submitting: false }">
    {{-- Animated background --}}
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
    <div class="dot-grid"></div>

    <main class="page">
        {{-- Hero (right in RTL) --}}
        <section class="hero">
            <span class="hero-badge">
                <i class="bi bi-stars"></i> قائمة طازجة كل يوم
            </span>

            <h1 class="hero-title">
                وجبتك المفضّلة،<br>
                بنكهة <span class="green">طازجة</span> و<span class="gold">أصالة</span>
            </h1>

            <p class="hero-desc">
                اطلب من أكثر من 200 طبق محضّر بأيدي طهاتنا — تتبّع طلبك من المطبخ حتى طاولتك،
                وادفع بسهولة بطريقتك المفضّلة. تجربة مطعم متكاملة، بضغطة واحدة.
            </p>

            <div class="features">
                <div class="feature">
                    <div class="feature-icon"><i class="bi bi-calendar-check"></i></div>
                    <div class="feature-title">حجز فوري</div>
                </div>
                <div class="feature">
                    <div class="feature-icon"><i class="bi bi-star-fill"></i></div>
                    <div class="feature-title">تقييم ممتاز</div>
                </div>
                <div class="feature">
                    <div class="feature-icon"><i class="bi bi-clock-fill"></i></div>
                    <div class="feature-title">طلب 24/7</div>
                </div>
            </div>

            <div class="hero-foot">
                <span><i class="bi bi-shield-check"></i> دفع آمن وموثوق</span>
                <span>© {{ date('Y') }} {{ config('restaurant.name') }} · جميع الحقوق محفوظة</span>
            </div>
        </section>

        {{-- Form (left in RTL) --}}
        <section class="form-pane">
            <div class="card-shell">
                <div class="card-glow"></div>

                <div class="auth-card">
                    {{-- Login panel --}}
                    <div class="login-panel" id="loginPanel">
                        <h2 class="auth-title">أهلاً بعودتك 👋</h2>
                        <p class="auth-subtitle">سجّل دخولك للطلب وتتبّع وجبتك</p>

                        {{-- Brand::logoUrl() already returns the dynamic monogram fallback when no
                             custom logo is uploaded — no need for an explicit hasCustomLogo branch. --}}
                        <img src="{{ \App\Helpers\Brand::logoUrl() }}"
                             alt="{{ \App\Models\Setting::get('site_name', config('restaurant.name')) }}"
                             class="auth-logo">

                        @if($errors->any())
                            <div class="alert alert-error" role="alert">
                                <i class="bi bi-exclamation-circle-fill"></i>
                                <span>{{ $errors->first() }}</span>
                            </div>
                        @endif

                        @if(session('success'))
                            <div class="alert alert-success" role="alert">
                                <i class="bi bi-check-circle-fill"></i>
                                <span>{{ session('success') }}</span>
                            </div>
                        @endif

                        <form action="{{ route('portal.login') }}" method="POST" autocomplete="on" id="loginForm" @submit="submitting = true">
                            @csrf

                            <div class="field">
                                <label class="field-label" for="identifierField">رقم الهاتف أو البريد الإلكتروني</label>
                                <div class="input-wrap">
                                    <i class="bi bi-person-fill icon-leading"></i>
                                    <input type="text" name="identifier" id="identifierField"
                                           class="input-control" dir="ltr"
                                           placeholder="مثال: 07xxxxxxxx"
                                           value="{{ old('identifier') }}"
                                           required autofocus autocomplete="username">
                                </div>
                            </div>

                            <div class="field">
                                <label class="field-label" for="passwordField">كلمة المرور</label>
                                <div class="input-wrap">
                                    <i class="bi bi-lock icon-leading"></i>
                                    <input :type="showPassword ? 'text' : 'password'" name="password" id="passwordField"
                                           class="input-control" dir="ltr"
                                           placeholder="••••••••"
                                           required autocomplete="current-password"
                                           @keydown="capsLock = $event.getModifierState && $event.getModifierState('CapsLock')"
                                           @keyup="capsLock = $event.getModifierState && $event.getModifierState('CapsLock')"
                                           @blur="capsLock = false">
                                    <button type="button"
                                            class="icon-trailing"
                                            @click="showPassword = !showPassword"
                                            :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'">
                                        <i class="bi" :class="showPassword ? 'bi-eye-slash' : 'bi-eye'"></i>
                                    </button>
                                </div>
                                <div class="caps-warn" id="capsWarn" :class="{ 'is-on': capsLock }" x-show="capsLock" x-cloak>
                                    <i class="bi bi-capslock-fill"></i>
                                    <span>Caps Lock مفعّل</span>
                                </div>
                            </div>

                            <input type="hidden" name="remember" value="1">

                            <button type="submit" class="btn-submit" id="submitBtn" :class="{ 'is-loading': submitting }" :disabled="submitting">
                                <span class="label">تسجيل الدخول</span>
                                <i class="bi bi-chevron-left icon-fwd"></i>
                                <span class="spinner"><i class="bi bi-arrow-repeat"></i></span>
                            </button>
                        </form>

                        <div class="signup-row">
                            <span>ليس لديك حساب؟</span>
                            <a href="{{ route('portal.register') }}">أنشئ حسابك الآن</a>
                        </div>
                    </div>

                    <p class="form-foot">بتسجيلك أنت توافق على الاستخدام الآمن للنظام</p>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
