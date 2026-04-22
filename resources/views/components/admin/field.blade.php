{{-- Display field (label + value) --}}
@props(['label', 'value' => null, 'boxed' => false])

@if($value)
<div @if($boxed) class="gen-field" @endif>
    <div class="{{ $boxed ? 'gen-field-label' : '' }}" @if(!$boxed) style="font-size: 0.78rem; color: var(--color-text-muted, #5B6780); font-weight: 600; margin-bottom: 0.15rem;" @endif>
        {{ $label }}
    </div>
    <div class="{{ $boxed ? 'gen-field-value' : '' }}" @if(!$boxed) style="font-size: 0.92rem; color: var(--color-text-main, #1F2937); font-weight: 500;" @endif>
        {{ $value }}
    </div>
</div>
@endif
