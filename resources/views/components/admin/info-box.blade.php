{{-- Info Box (info, warning, danger, success) --}}
@props(['type' => 'info', 'icon' => null])

@php
    $styles = [
        'info'    => ['bg' => '--color-info-bg, #F0F9FF', 'border' => '--color-info-border, #BAE6FD', 'text' => '--color-info-text, #0369A1', 'defaultIcon' => 'bi-info-circle'],
        'warning' => ['bg' => '--color-warning-bg, #FFFBEB', 'border' => '--color-warning-border, #FCD34D', 'text' => '--color-warning-text, #B45309', 'defaultIcon' => 'bi-exclamation-triangle'],
        'danger'  => ['bg' => '--color-danger-bg, #FEF2F2', 'border' => '--color-danger-border, #FECACA', 'text' => '--color-danger-text, #B91C1C', 'defaultIcon' => 'bi-exclamation-circle'],
        'success' => ['bg' => '--color-success-bg, #ECFDF5', 'border' => '--color-success-border, #A7F3D0', 'text' => '--color-success-text, #047857', 'defaultIcon' => 'bi-check-circle'],
        'primary' => ['bg' => '--color-primary-soft, #EEF2FF', 'border' => '--color-primary-soft-border, #D6DCFF', 'text' => '--color-primary, #24308F', 'defaultIcon' => 'bi-info-circle'],
    ];
    $s = $styles[$type] ?? $styles['info'];
    $iconClass = $icon ?? $s['defaultIcon'];
@endphp

<div style="background: var({{ $s['bg'] }}); border: 1px solid var({{ $s['border'] }}); border-radius: 8px; padding: 0.65rem 1rem; font-size: 0.85rem; color: var({{ $s['text'] }});" {{ $attributes }}>
    <i class="bi {{ $iconClass }} me-1"></i>
    {{ $slot }}
</div>
