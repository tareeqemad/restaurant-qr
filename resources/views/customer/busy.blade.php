<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1f4733">
<title>الطاولة مشغولة · {{ config('restaurant.name') }}</title>
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
    --brand-soft: #e4ede6;
    --accent: #b8872a;
    --accent-dark: #8a6920;
    --accent-soft: #f5ebd0;
    --gold: #d4a550;
    --gold-light: #e8c775;
    --cream: #faf5eb;
}
* { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
body {
    font-family: 'Tajawal', Arial, sans-serif;
    background:
        linear-gradient(135deg, rgba(13,35,23,.9) 0%, rgba(31,71,51,.85) 100%),
        url("{{ asset('assets/brand/pattern.svg') }}"),
        linear-gradient(135deg, #0d2317 0%, #1f4733 100%);
    background-size: cover, 240px 240px, cover;
    background-repeat: no-repeat, repeat, no-repeat;
    background-attachment: fixed;
    min-height: 100vh;
    display: flex; align-items: center;
    padding: 1rem;
    position: relative;
}
body::before,
body::after {
    content: ''; position: fixed;
    width: 420px; height: 420px;
    border-radius: 50%;
    filter: blur(80px);
    opacity: .3;
    pointer-events: none;
}
body::before { background: radial-gradient(circle, var(--accent), transparent 70%); top: -160px; inset-inline-end: -160px; }
body::after  { background: radial-gradient(circle, var(--gold-light), transparent 70%); bottom: -160px; inset-inline-start: -160px; }

.wrap { max-width: 480px; margin: 0 auto; width: 100%; position: relative; z-index: 1; }

.card {
    border: 0;
    border-radius: 24px;
    box-shadow: 0 24px 64px rgba(0,0,0,.35), 0 0 0 1px rgba(184,135,42,.2);
    overflow: hidden;
    animation: slide-in .5s cubic-bezier(.34,1.56,.64,1);
    background: white;
}
@keyframes slide-in { 0% { transform: translateY(24px) scale(.95); opacity: 0; } 100% { transform: translateY(0) scale(1); opacity: 1; } }

.card-top {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white;
    padding: 1.75rem 1rem 3rem;
    text-align: center;
    position: relative;
    border-bottom: 3px solid var(--accent);
    overflow: hidden;
}
.card-top::before,
.card-top::after {
    content: ''; position: absolute;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(184,135,42,.3), transparent 70%);
}
.card-top::before { width: 160px; height: 160px; top: -50px; right: -40px; }
.card-top::after  { width: 140px; height: 140px; bottom: -40px; left: -40px; background: radial-gradient(circle, rgba(184,135,42,.2), transparent 70%); }

.icon-circle {
    width: 88px; height: 88px;
    background: var(--accent);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--brand-dark);
    font-size: 2.7rem;
    box-shadow: 0 10px 30px rgba(0,0,0,.25), inset 0 -3px 8px rgba(0,0,0,.1);
    position: relative; z-index: 1;
    animation: pulse-ring 2.2s infinite;
}
@keyframes pulse-ring {
    0%, 100% { box-shadow: 0 10px 30px rgba(0,0,0,.25), inset 0 -3px 8px rgba(0,0,0,.1), 0 0 0 0 rgba(184,135,42,.5); }
    50%      { box-shadow: 0 10px 30px rgba(0,0,0,.25), inset 0 -3px 8px rgba(0,0,0,.1), 0 0 0 14px rgba(184,135,42,0); }
}

.card-top h3 {
    font-weight: 900;
    margin: 1rem 0 .25rem;
    position: relative; z-index: 1;
    font-size: 1.4rem;
    letter-spacing: -.01em;
}
.card-top .meta {
    opacity: .92;
    font-size: .9rem;
    position: relative; z-index: 1;
}

.table-num {
    background: var(--accent);
    color: var(--brand-dark);
    padding: 2px 14px;
    border-radius: 99px;
    font-weight: 900;
    display: inline-block;
    margin: 0 4px;
    box-shadow: 0 2px 6px rgba(0,0,0,.2);
}

.card-body {
    padding: 1.75rem 1.25rem 1.5rem;
    margin-top: -1.75rem;
    background: white;
    border-radius: 24px 24px 0 0;
    position: relative; z-index: 2;
}

.info-box {
    background: linear-gradient(135deg, rgba(184,135,42,.08), rgba(31,71,51,.04));
    border: 1px solid rgba(184,135,42,.2);
    border-right: 4px solid var(--accent);
    border-radius: 14px;
    padding: 1rem 1.1rem;
    text-align: right;
    color: var(--brand-dark);
    font-size: .92rem;
    line-height: 1.6;
    margin-bottom: 1.25rem;
}
.info-box strong {
    color: var(--brand-dark);
    display: flex; align-items: center;
    gap: 6px;
    margin-bottom: 6px;
    font-size: .95rem;
}
.info-box strong i { color: var(--accent); font-size: 1.1rem; }

.btn-join {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white;
    border: 0;
    border-radius: 99px;
    padding: 15px 24px;
    font-weight: 800;
    font-size: 1rem;
    width: 100%;
    box-shadow: 0 10px 28px rgba(18,45,30,.35), inset 0 1px 0 rgba(255,255,255,.1);
    transition: transform .15s, box-shadow .15s;
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}
.btn-join::before {
    content: '';
    position: absolute;
    top: 0; right: -100%;
    width: 100%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(184,135,42,.3), transparent);
    transition: right .5s;
}
.btn-join:hover::before { right: 100%; }
.btn-join:hover {
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 14px 36px rgba(18,45,30,.45);
}
.btn-join:active { transform: translateY(0); }
.btn-join i { color: var(--gold-light); }

.foot-note {
    font-size: .82rem;
    color: var(--brand-dark);
    margin-top: 1rem;
    text-align: center;
    line-height: 1.6;
    padding: .85rem 1rem;
    background: rgba(228, 237, 230, .4);
    border: 1px dashed rgba(31,71,51,.15);
    border-radius: 12px;
}
.foot-note i { color: var(--accent); margin-inline-end: 4px; }

/* Brand footer */
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
        <div class="card-top">
            <div class="icon-circle"><i class="bi bi-hourglass-split"></i></div>
            <h3>
                الطاولة <span class="table-num">{{ $table->number }}</span> مشغولة
            </h3>
            <div class="meta">
                جلسة نشطة منذ {{ $session->opened_at->diffForHumans() }}
                @if($active_orders > 0)
                    · <strong>{{ $active_orders }}</strong> طلب جارٍ
                @endif
            </div>
        </div>

        <div class="card-body">
            <div class="info-box">
                <strong><i class="bi bi-people-fill"></i> هل أنت مع نفس المجموعة؟</strong>
                يمكنك الانضمام للجلسة الحالية لإضافة أصناف للطلب الموجود أو إنشاء طلب جديد على نفس الطاولة.
            </div>

            <a href="{{ url('/menu/'.$token.'?join=1') }}" class="btn-join">
                <i class="bi bi-check2-circle"></i>
                انضم للجلسة (نفس المجموعة)
            </a>

            <div class="foot-note">
                <i class="bi bi-info-circle-fill"></i>
                إذا كنت زبون جديد والطاولة مفترض أنها فارغة، الرجاء التواصل مع الجرسون.
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
