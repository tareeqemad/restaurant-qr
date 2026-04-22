{{-- Section inside card body --}}
@props(['title', 'icon' => null, 'boxed' => false])

<div class="invoice-section" @if($boxed) style="background: #FAFCFF; border: 1px solid var(--color-border, #E5E7EB); border-radius: 10px; padding: 1.25rem;" @endif>
    @if($title)
        <div class="invoice-section-header">
            @if($icon)<i class="bi {{ $icon }}"></i>@endif
            {{ $title }}
        </div>
    @endif
    {{ $slot }}
</div>
