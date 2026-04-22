{{-- KPI Card --}}
@props(['icon', 'color' => 'primary', 'value', 'label', 'unit' => null, 'link' => null, 'size' => 'default'])

<div {{ $attributes->merge(['class' => 'dash-kpi' . ($size === 'lg' ? ' dash-kpi-lg' : '')]) }}>
    <div class="dash-kpi-icon kpi-{{ $color }}">
        <i class="bi {{ $icon }}"></i>
    </div>
    <div class="dash-kpi-body">
        <div class="dash-kpi-value">{{ $value }}</div>
        @if($unit)
            <div class="dash-kpi-unit">{{ $unit }}</div>
        @endif
        <div class="dash-kpi-label">{{ $label }}</div>
        @if(isset($badges))
            <div class="dash-kpi-badges">{{ $badges }}</div>
        @endif
        @if(isset($extra))
            {{ $extra }}
        @endif
    </div>
    @if($link)
        <a href="{{ $link }}" class="dash-kpi-link"><i class="bi bi-arrow-left"></i></a>
    @endif
</div>
