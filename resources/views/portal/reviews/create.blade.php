@extends('portal.layout')
@section('title', 'اكتب تقييماً')

@section('content')
<div class="pf-card" style="max-width: 560px; margin: 0 auto;">
    <h1 class="pf-title">كيف كانت زيارتك؟</h1>
    <p class="pf-subtitle">
        {{ $reservation->branch->name }} · {{ $reservation->reserved_for->translatedFormat('d M Y') }}
    </p>

    <form method="POST" action="{{ route('portal.reviews.store', $reservation) }}">
        @csrf

        {{-- ─── Star rating ─────────────────────────────────────── --}}
        <div class="pf-rate" role="radiogroup" aria-label="تقييم الزيارة">
            @for($i = 5; $i >= 1; $i--)
                <input type="radio" id="r{{ $i }}" name="rating" value="{{ $i }}"
                       @checked(old('rating') == $i)
                       required>
                <label for="r{{ $i }}" title="{{ $i }} من 5">
                    <i class="bi bi-star-fill"></i>
                </label>
            @endfor
        </div>
        @error('rating')<div class="pf-error" style="text-align:center;">{{ $message }}</div>@enderror

        <div class="pf-input-group mt-4">
            <label class="pf-label" for="title">عنوان مختصر (اختياري)</label>
            <input type="text" id="title" name="title" value="{{ old('title') }}"
                   class="pf-input @error('title') has-error @enderror"
                   placeholder="مثلاً: خدمة ممتازة وأكل لذيذ">
            @error('title')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="body">شاركنا تفاصيل تجربتك (اختياري)</label>
            <textarea id="body" name="body" rows="5"
                      class="pf-input @error('body') has-error @enderror"
                      placeholder="ما الذي أعجبك؟ ما الذي يمكن تحسينه؟">{{ old('body') }}</textarea>
            @error('body')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="pf-btn">
                <i class="bi bi-send"></i> إرسال التقييم
            </button>
            <a href="{{ route('portal.reviews.index') }}" class="pf-btn pf-btn--ghost">إلغاء</a>
        </div>
    </form>
</div>

@push('styles')
<style>
    /* Star rating: render labels as gold stars; checking a higher star
       cascades to the lower stars via :hover/:checked + sibling combinators.
       Inputs are visually hidden — accessible for SR and keyboard. */
    .pf-rate {
        display: inline-flex;
        flex-direction: row-reverse;   /* So *earlier* HTML stars sit to the right */
        justify-content: center;
        gap: 6px;
        padding: 12px 0;
        margin: 0 auto;
        width: 100%;
    }
    .pf-rate input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .pf-rate label {
        cursor: pointer;
        font-size: 2.2rem;
        color: #e5e7eb;
        transition: color .12s ease, transform .12s ease;
        line-height: 1;
        padding: 4px;
    }
    .pf-rate label:hover {
        transform: scale(1.1);
    }
    .pf-rate input:focus-visible + label {
        outline: 2px solid rgba(46,125,50,.4);
        outline-offset: 2px;
        border-radius: 6px;
    }

    /* Highlight checked star + every star to its right (lower values in DOM). */
    .pf-rate input:checked ~ label,
    .pf-rate label:hover,
    .pf-rate label:hover ~ label {
        color: var(--gold);
    }
</style>
@endpush
@endsection
