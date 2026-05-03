@extends('portal.layout')
@section('title', 'حجز طاولة')

@section('content')
<div class="pf-card" style="max-width: 520px; margin: 0 auto;">
    <h1 class="pf-title">احجز طاولتك</h1>
    <p class="pf-subtitle">سنُؤكّد حجزك خلال دقائق — أو فوراً إذا توفّرت الطاولة.</p>

    <form method="POST" action="{{ route('portal.reservations.store') }}">
        @csrf

        <div class="pf-input-group">
            <label class="pf-label" for="branch_id">الفرع</label>
            <select id="branch_id" name="branch_id"
                    class="pf-input @error('branch_id') has-error @enderror" required>
                <option value="">— اختر الفرع —</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}"
                            @selected(old('branch_id', auth('customer')->user()->default_branch_id) == $branch->id)>
                        {{ $branch->name }}{{ $branch->city ? ' — '.$branch->city : '' }}
                    </option>
                @endforeach
            </select>
            @error('branch_id')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="reserved_for">الموعد (التاريخ والوقت)</label>
            <input type="datetime-local" id="reserved_for" name="reserved_for"
                   value="{{ old('reserved_for') }}"
                   class="pf-input @error('reserved_for') has-error @enderror"
                   min="{{ now()->format('Y-m-d\\TH:i') }}"
                   required>
            @error('reserved_for')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="party_size">عدد الضيوف</label>
            <input type="number" id="party_size" name="party_size"
                   value="{{ old('party_size', 2) }}"
                   class="pf-input @error('party_size') has-error @enderror"
                   min="1" max="30" required>
            @error('party_size')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="pf-input-group">
            <label class="pf-label" for="customer_notes">ملاحظات (اختياري)</label>
            <textarea id="customer_notes" name="customer_notes" rows="3"
                      class="pf-input @error('customer_notes') has-error @enderror"
                      placeholder="مثلاً: مناسبة عيد ميلاد، طاولة بجانب النافذة…">{{ old('customer_notes') }}</textarea>
            @error('customer_notes')<div class="pf-error">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="pf-btn">
                <i class="bi bi-check2-circle"></i> تأكيد الحجز
            </button>
            <a href="{{ route('portal.reservations.index') }}" class="pf-btn pf-btn--ghost">إلغاء</a>
        </div>
    </form>
</div>
@endsection
