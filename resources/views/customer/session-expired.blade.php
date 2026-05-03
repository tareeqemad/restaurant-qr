<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1f4733">
<title>انتهت الجلسة · {{ config('restaurant.name') }}</title>
<link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
<link href="{{ asset('assets/dashtic/libs/bootstrap/css/bootstrap.rtl.min.css') }}" rel="stylesheet">
<link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Cinzel:wght@600;700&display=swap" rel="stylesheet">
<style>
:root {
    --brand: #1f4733;
    --brand-dark: #122d1e;
    --accent: #b8872a;
    --accent-dark: #8a6920;
    --gold-light: #e8c775;
}
* { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
body {
    font-family: 'Tajawal', Arial, sans-serif;
    background:
        linear-gradient(135deg, rgba(13,35,23,.9), rgba(31,71,51,.85)),
        url("{{ asset('assets/brand/pattern.svg') }}"),
        linear-gradient(135deg, #0d2317, #1f4733);
    background-size: cover, 240px 240px, cover;
    background-attachment: fixed;
    min-height: 100vh;
    display: flex; align-items: center;
    padding: 1rem;
    position: relative;
}
body::before, body::after {
    content: ''; position: fixed;
    width: 400px; height: 400px;
    border-radius: 50%;
    filter: blur(80px);
    opacity: .25;
    pointer-events: none;
}
body::before { background: radial-gradient(circle, var(--accent), transparent 70%); top: -150px; right: -150px; }
body::after  { background: radial-gradient(circle, var(--gold-light), transparent 70%); bottom: -150px; left: -150px; }

.wrap { max-width: 460px; margin: 0 auto; width: 100%; position: relative; z-index: 1; }

.card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 24px 64px rgba(0,0,0,.35), 0 0 0 1px rgba(184,135,42,.2);
    overflow: hidden;
    text-align: center;
    animation: slide-in .5s cubic-bezier(.34,1.56,.64,1);
}
@keyframes slide-in { 0% { transform: translateY(24px) scale(.95); opacity: 0; } 100% { transform: translateY(0) scale(1); opacity: 1; } }

.card::before {
    content: ''; display: block; height: 4px;
    background: linear-gradient(90deg, var(--accent) 0%, var(--brand) 50%, var(--accent) 100%);
}

.card-inner { padding: 2rem 1.5rem 1.75rem; }

.icon-wrap {
    width: 110px; height: 110px;
    margin: 0 auto 1.25rem;
    background: linear-gradient(135deg, rgba(184,135,42,.15), rgba(184,135,42,.05));
    border: 2px dashed rgba(184,135,42,.3);
    border-radius: 28px;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--accent);
    font-size: 3rem;
    position: relative;
}
.icon-wrap::after {
    content: ''; position: absolute; inset: -6px;
    border: 1px solid rgba(184,135,42,.2);
    border-radius: 32px;
    animation: pulse-dash 3s ease-in-out infinite;
}
@keyframes pulse-dash {
    0%, 100% { transform: scale(1); opacity: .5; }
    50%      { transform: scale(1.05); opacity: 1; }
}

.title {
    color: var(--brand-dark);
    font-weight: 900;
    font-size: 1.35rem;
    margin: 0 0 .5rem;
    letter-spacing: -.01em;
}
.subtitle {
    color: #78716c;
    font-size: .92rem;
    line-height: 1.6;
    margin-bottom: 1.25rem;
}

.info-box {
    background: linear-gradient(135deg, rgba(184,135,42,.08), rgba(31,71,51,.04));
    border: 1px solid rgba(184,135,42,.2);
    border-right: 4px solid var(--accent);
    border-radius: 12px;
    padding: .85rem 1rem;
    text-align: right;
    color: var(--brand-dark);
    font-size: .85rem;
    line-height: 1.55;
    display: flex;
    align-items: flex-start;
    gap: .65rem;
}
.info-box i {
    color: var(--accent);
    font-size: 1.1rem;
    flex-shrink: 0;
    margin-top: 1px;
}

.brand-footer {
    margin-top: 1.5rem;
    text-align: center;
    color: rgba(255,255,255,.7);
    font-size: .75rem;
    letter-spacing: .08em;
}
.brand-footer .brand-name {
    font-family: 'Cinzel', serif;
    font-weight: 600;
    color: var(--gold-light);
    letter-spacing: .12em;
    display: block;
    font-size: .95rem;
    margin-bottom: 2px;
}
</style>
@include('partials.runtime-theme')
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="card-inner">
            <div class="icon-wrap"><i class="bi bi-hourglass-split"></i></div>
            <h3 class="title">انتهت جلسة الطاولة</h3>
            <p class="subtitle">{{ $message ?? 'انتهت صلاحية الجلسة. يرجى مسح رمز QR مرة أخرى للعودة إلى القائمة.' }}</p>
            <div class="info-box">
                <i class="bi bi-qr-code"></i>
                <div>
                    امسح الـ QR Code الموجود على طاولتك لبدء جلسة جديدة. إذا لم يكن موجوداً أو لم يعمل، تواصل مع أحد موظفي المطعم.
                </div>
            </div>
        </div>
    </div>

    <div class="brand-footer">
        <span class="brand-name">RELAX</span>
        <span>نظام إدارة المطعم</span>
    </div>
</div>
</body>
</html>
