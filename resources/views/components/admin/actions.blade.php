{{-- Form Actions (Save/Cancel) --}}
@props(['cancelRoute' => null, 'cancelLabel' => 'إلغاء', 'submitLabel' => 'حفظ', 'submitIcon' => 'bi-check-lg', 'submitClass' => 'btn-success', 'formId' => null])

<div class="d-flex justify-content-end gap-2 mt-3 pt-3" style="border-top: 1px solid var(--color-border-soft, #EDF1F5);">
    @if($cancelRoute)
        <a href="{{ $cancelRoute }}" class="btn btn-outline-secondary">
            <i class="bi bi-x-circle me-1"></i>{{ $cancelLabel }}
        </a>
    @endif
    {{ $slot }}
    <button type="submit" class="btn {{ $submitClass }}" @if($formId) form="{{ $formId }}" @endif>
        <i class="bi {{ $submitIcon }} me-1"></i>{{ $submitLabel }}
    </button>
</div>
