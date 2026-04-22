{{--
  Reusable empty-state component for admin index pages.

  Props:
    icon     Bootstrap Icons class, shown in a gold chip (default "bi-inbox").
    title    Bold headline (optional — falls back to message only).
    message  Secondary line (default "لا توجد بيانات").
    compact  Smaller padding for tight containers.

  Slot:
    cta      Optional call-to-action button(s) shown below the message.

  See docs/empty-state.md for usage examples.
--}}

@props([
    'icon'    => 'bi-inbox',
    'title'   => null,
    'message' => 'لا توجد بيانات',
    'compact' => false,
    'colspan' => null,
])

<div {{ $attributes->merge([
        'class' => 'empty-state ' . ($compact ? 'empty-state--compact' : ''),
     ]) }}>
    <div class="empty-state-illustration">
        <div class="empty-state-icon-ring">
            <div class="empty-state-icon">
                <i class="bi {{ $icon }}"></i>
            </div>
        </div>
        {{-- Decorative dots around the icon --}}
        <svg class="empty-state-decor" viewBox="0 0 200 200" aria-hidden="true">
            <circle cx="30"  cy="100" r="2.5" fill="currentColor" opacity=".35"/>
            <circle cx="170" cy="100" r="2.5" fill="currentColor" opacity=".35"/>
            <circle cx="100" cy="30"  r="2"   fill="currentColor" opacity=".25"/>
            <circle cx="100" cy="170" r="2"   fill="currentColor" opacity=".25"/>
            <circle cx="50"  cy="50"  r="1.5" fill="currentColor" opacity=".2"/>
            <circle cx="150" cy="150" r="1.5" fill="currentColor" opacity=".2"/>
            <circle cx="50"  cy="150" r="1.5" fill="currentColor" opacity=".2"/>
            <circle cx="150" cy="50"  r="1.5" fill="currentColor" opacity=".2"/>
        </svg>
    </div>

    @if($title)
        <h4 class="empty-state-title">{{ $title }}</h4>
    @endif

    <p class="empty-state-message">{{ $message }}</p>

    @isset($cta)
        <div class="empty-state-cta">
            {{ $cta }}
        </div>
    @endisset
</div>
