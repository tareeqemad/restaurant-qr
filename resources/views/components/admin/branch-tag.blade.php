@props(['branch'])

@php
    /**
     * Compact, color-coded chip for an owning branch — used on listings
     * the Super Admin views in "all branches" mode so each row clearly
     * announces which branch produced it. The hue is deterministic per
     * branch id (matches the in-header switcher avatar) so the same
     * branch always shows the same color across the app.
     */
    if (! $branch) return;
    $hue = ($branch->id * 47) % 360;
@endphp

<span class="branch-tag" style="--bt-hue: {{ $hue }};" title="{{ $branch->name }}">
    <span class="branch-tag__dot"></span>
    {{ $branch->name }}
</span>

@once
@push('styles')
<style>
    .branch-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 9px;
        background: hsl(var(--bt-hue, 150) 38% 96%);
        color: hsl(var(--bt-hue, 150) 50% 25%);
        border: 1px solid hsl(var(--bt-hue, 150) 38% 80%);
        border-radius: 6px;
        font-size: .75rem;
        font-weight: 700;
        line-height: 1.4;
        white-space: nowrap;
    }
    .branch-tag__dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: hsl(var(--bt-hue, 150) 50% 45%);
        flex-shrink: 0;
    }
</style>
@endpush
@endonce
