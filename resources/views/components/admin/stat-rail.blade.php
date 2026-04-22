{{--
  Horizontal strip of stat cards — place above the main data panel to give
  context on what the user is looking at.

  Pass an array of stats via the :stats prop OR use the slot for custom items.

  Array form:
    :stats="[
        ['label' => 'بانتظار', 'value' => 5, 'icon' => 'bi-clock', 'color' => 'accent'],
        ['label' => 'معتمد', 'value' => 12, 'icon' => 'bi-check-circle', 'color' => 'primary'],
        ['label' => 'إيراد اليوم', 'value' => '850 د.أ', 'icon' => 'bi-cash', 'color' => 'success'],
    ]"

  Colors: accent (gold) | primary (forest) | success (green tint) | warning | danger | muted
--}}

@props([
    'stats' => [],
])

<div {{ $attributes->merge(['class' => 'stat-rail']) }}>
    @foreach($stats as $s)
        @php
            $color = $s['color'] ?? 'primary';
            $trend = $s['trend'] ?? null;   // optional: "+12%" or "-3%"
            $link  = $s['link']  ?? null;
        @endphp
        <{{ $link ? 'a' : 'div' }}
            class="stat-rail-item stat-rail--{{ $color }}"
            @if($link) href="{{ $link }}" @endif>
            <div class="stat-rail-icon">
                <i class="bi {{ $s['icon'] ?? 'bi-bar-chart' }}"></i>
            </div>
            <div class="stat-rail-body">
                <div class="stat-rail-label">{{ $s['label'] }}</div>
                <div class="stat-rail-value">{{ $s['value'] }}</div>
                @if($trend)
                    <div class="stat-rail-trend stat-rail-trend--{{ str_starts_with($trend, '-') ? 'down' : 'up' }}">
                        <i class="bi bi-{{ str_starts_with($trend, '-') ? 'arrow-down' : 'arrow-up' }}"></i>
                        {{ ltrim($trend, '+-') }}
                    </div>
                @endif
            </div>
        </{{ $link ? 'a' : 'div' }}>
    @endforeach

    {{ $slot }}
</div>
