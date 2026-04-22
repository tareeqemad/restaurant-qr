<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1f4733">
<title>خارج الخدمة · {{ config('restaurant.name') }}</title>
<link rel="icon" href="{{ asset('assets/dashtic/images/brand-logos/favicon.ico') }}">
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
    --danger: #b91c1c;
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
}
body::before, body::after {
    content: ''; position: fixed;
    width: 400px; height: 400px;
    border-radius: 50%;
    filter: blur(80px);
    opacity: .22;
    pointer-events: none;
}
body::before { background: radial-gradient(circle, #e48a8a, transparent 70%); top: -150px; right: -150px; }
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
    background: linear-gradient(90deg, var(--accent) 0%, var(--danger) 50%, var(--accent) 100%);
}

.card-inner { padding: 2.25rem 1.5rem 1.75rem; }

.icon-wrap {
    width: 120px; height: 120px;
    margin: 0 auto 1.25rem;
    background: linear-gradient(135deg, rgba(185,28,28,.12), rgba(185,28,28,.04));
    border: 2px solid rgba(185,28,28,.2);
    border-radius: 30px;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--danger);
    font-size: 3.5rem;
}

.title {
    color: var(--brand-dark);
    font-weight: 900;
    font-size: 1.3rem;
    margin: 0 0 .4rem;
    letter-spacing: -.01em;
}
.subtitle {
    color: #78716c;
    font-size: .92rem;
    line-height: 1.6;
    margin-bottom: 1.25rem;
}

.table-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--accent);
    color: var(--brand-dark);
    padding: 3px 14px;
    border-radius: 99px;
    font-weight: 900;
    margin-right: 4px;
    margin-left: 4px;
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
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="card-inner">
            <div class="icon-wrap"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <h3 class="title">
                الطاولة <span class="table-badge">{{ $table->number }}</span> خارج الخدمة
            </h3>
            <p class="subtitle">هذه الطاولة غير متاحة حالياً لاستقبال الطلبات.</p>
            <div class="info-box">
                <i class="bi bi-headset"></i>
                <div>
                    الرجاء التواصل مع أحد موظفي المطعم لتوجيهك إلى طاولة أخرى أو للمساعدة.
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
