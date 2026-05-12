@extends('portal.layout')
@section('title', 'الإشعارات والعروض')

@section('content')
@php
    use Illuminate\Support\Carbon;
    $hasUnread = $notifications->where('read_at', null)->isNotEmpty();
@endphp

<div class="pf-hero">
    <div class="pf-hero__lead">
        <h1 class="pf-hero__title">
            <i class="bi bi-bell-fill" style="color: #F9A825;"></i>
            الإشعارات والعروض
        </h1>
        <p class="pf-hero__subtitle">
            كل الإعلانات والعروض الموجَّهة لك.
            @if($notifications->total() > 0)
                <strong>{{ number_format($notifications->total()) }}</strong> رسالة
                · غير مقروء: <strong>{{ number_format($notifications->where('read_at', null)->count()) }}</strong>
            @endif
        </p>
    </div>
    @if($hasUnread)
        <div class="pf-hero__cta">
            <form method="POST" action="{{ route('portal.notifications.read-all') }}" style="margin:0;">
                @csrf
                <button class="pf-btn pf-btn--ghost">
                    <i class="bi bi-check2-all"></i> اعتبارها كلها مقروءة
                </button>
            </form>
        </div>
    @endif
</div>

@if($notifications->isEmpty())
    <div class="pf-card text-center" style="padding: 48px 24px;">
        <div style="font-size: 3rem; opacity: .25; margin-bottom: 12px;">
            <i class="bi bi-bell-slash"></i>
        </div>
        <h2 style="font-size: 1.1rem; margin: 0 0 8px;">لا توجد إشعارات بعد</h2>
        <p class="text-muted" style="margin: 0; font-size: .9rem;">
            عند نشر عروض وحملات تسويقية، ستصلك هنا.
        </p>
    </div>
@else
    <div class="pf-notifs">
        @foreach($notifications as $n)
            @php
                $data = $n->data ?? [];
                $color = $data['extra']['color'] ?? '#F9A825';
                $icon  = $data['icon'] ?? 'bi-megaphone-fill';
                $img   = $data['extra']['image_path'] ?? null;
                $isUnread = $n->read_at === null;
                $cta = $data['action_url'] ?? null;
                $ctaLabel = $data['action_label'] ?? 'عرض التفاصيل';
            @endphp

            <article class="pf-notif {{ $isUnread ? 'is-unread' : '' }}"
                     style="--ann-color: {{ $color }};">
                @if($isUnread)
                    <span class="pf-notif__dot" title="غير مقروء"></span>
                @endif

                <div class="pf-notif__icon" style="background: {{ $color }}15; color: {{ $color }};">
                    <i class="bi {{ $icon }}"></i>
                </div>

                <div class="pf-notif__body">
                    <div class="pf-notif__head">
                        <strong>{{ $data['title'] ?? '—' }}</strong>
                        <small>{{ $n->created_at->locale('ar')->diffForHumans() }}</small>
                    </div>
                    <p>{{ $data['body'] ?? '' }}</p>

                    @if($img)
                        <img src="{{ asset('storage/'.$img) }}" alt="" class="pf-notif__img">
                    @endif

                    <div class="pf-notif__actions">
                        @if($cta)
                            <form method="POST" action="{{ route('portal.notifications.read', $n->id) }}" style="margin:0;">
                                @csrf
                                <button class="pf-notif__cta" style="background: {{ $color }};">
                                    {{ $ctaLabel }}
                                    <i class="bi bi-arrow-left"></i>
                                </button>
                            </form>
                        @endif
                        @if($isUnread && ! $cta)
                            <form method="POST" action="{{ route('portal.notifications.read', $n->id) }}" style="margin:0;">
                                @csrf
                                <button class="pf-notif__mark">
                                    <i class="bi bi-check2"></i> مقروء
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
@endif

<style>
.pf-notifs { display: flex; flex-direction: column; gap: 12px; }

.pf-notif {
    position: relative;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 18px 20px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    transition: border-color .15s, box-shadow .15s, transform .15s;
}
.pf-notif:hover {
    border-color: var(--ann-color, #F9A825);
    box-shadow: 0 6px 16px rgba(15,45,34,.06);
    transform: translateY(-1px);
}
.pf-notif.is-unread {
    background: linear-gradient(135deg, #fff 0%, color-mix(in srgb, var(--ann-color) 4%, white) 100%);
    border-right: 3px solid var(--ann-color, #F9A825);
}

.pf-notif__dot {
    position: absolute;
    top: 14px; inset-inline-end: 14px;
    width: 8px; height: 8px;
    background: var(--ann-color, #F9A825);
    border-radius: 50%;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ann-color) 25%, transparent);
}

.pf-notif__icon {
    width: 48px; height: 48px;
    border-radius: 14px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

.pf-notif__body { flex: 1; min-width: 0; }
.pf-notif__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
    flex-wrap: wrap;
}
.pf-notif__head strong {
    font-size: 1rem;
    font-weight: 800;
    color: var(--ink);
    line-height: 1.3;
}
.pf-notif__head small {
    font-size: .75rem;
    color: var(--muted-2);
    flex-shrink: 0;
}

.pf-notif__body p {
    font-size: .9rem;
    color: var(--ink-2);
    line-height: 1.7;
    margin: 0 0 12px;
}

.pf-notif__img {
    width: 100%; max-height: 180px;
    object-fit: cover;
    border-radius: 10px;
    margin-bottom: 12px;
}

.pf-notif__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.pf-notif__cta {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--ann-color, #F9A825);
    color: #fff; border: 0;
    border-radius: 10px;
    padding: 9px 18px;
    font-weight: 700; font-size: .88rem;
    font-family: inherit;
    cursor: pointer;
    transition: transform .15s, filter .15s;
    box-shadow: 0 4px 10px rgba(0,0,0,.10);
}
.pf-notif__cta:hover { transform: translateY(-1px); filter: brightness(1.06); }
.pf-notif__mark {
    background: transparent;
    color: var(--muted);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 7px 14px;
    font-size: .82rem; font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 5px;
    transition: all .15s;
}
.pf-notif__mark:hover { background: #f3f4f6; color: var(--ink); }

@media (max-width: 480px) {
    .pf-notif { padding: 14px; gap: 10px; }
    .pf-notif__icon { width: 40px; height: 40px; font-size: 1.1rem; }
    .pf-notif__head strong { font-size: .92rem; }
}
</style>
@endsection
