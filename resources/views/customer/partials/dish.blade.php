@php
    // Ingredient names from the recipe — only those with an ingredient row
    // (defensive: a recipe line could exist whose ingredient was deleted).
    // Optional lines are still listed so the diner can see everything the
    // dish "may contain", which matches how the kitchen prep card reads.
    $ingredients = $item->relationLoaded('recipeItems')
        ? $item->recipeItems
            ->map(fn ($r) => $r->ingredient?->name)
            ->filter()
            ->values()
        : collect();

    // Live availability: BOTH the manual `is_available` flag AND a real-time
    // stock check (recursively expanding composite ingredients). We carve
    // the two reasons apart so the card can say "غير متوفر" vs "نفد المخزون"
    // — different messages for different operator intent.
    $manuallyAvailable = (bool) $item->is_available;
    $shortages         = $manuallyAvailable ? $item->stockShortages(1.0) : [];
    $inStock           = empty($shortages);
    $canOrder          = $manuallyAvailable && $inStock;
    $unavailReason     = ! $manuallyAvailable
        ? 'غير متوفر'
        : (! $inStock ? 'نفد المخزون' : null);
@endphp

    $payload = [
        'id' => $item->id,
        'name' => $item->name,
        'description' => $item->description,
        'price' => (float) $item->price,
        'image' => $item->imageUrl(),
        'ingredients' => $ingredients->all(),
        'has_modifiers' => $item->modifierGroups->count() > 0,
        'modifier_groups' => $item->modifierGroups->map(fn($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'min_select' => $g->min_select,
            'max_select' => $g->max_select,
            'required' => (bool) $g->required,
            'modifiers' => $g->modifiers->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price_delta' => (float) $m->price_delta,
            ])->values()->toArray(),
        ])->values()->toArray(),
    ];
    $hasModifiers = $item->modifierGroups->count() > 0;
    $searchText = trim(collect([
        $item->name,
        $item->description,
        $item->allergens->pluck('name')->join(' '),
        $ingredients->join(' '),
    ])->filter()->join(' '));
@endphp
<div class="dish {{ $canOrder ? '' : 'is-unavailable' }} {{ $hasModifiers ? 'has-mods' : '' }}"
     x-show="matchesSearch({{ \Illuminate\Support\Js::from($searchText) }})"
     x-transition.opacity.duration.150ms
     data-menu-search="{{ $searchText }}"
     @if($canOrder)
     @click="onCardClick({{ \Illuminate\Support\Js::from($payload) }}, $event)"
     @endif>
    <div class="dish-img">
        <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}" loading="lazy" data-dish-img="{{ $item->id }}">
        @if($item->is_featured && $canOrder)
            <span class="badge-today">متاح اليوم</span>
        @endif
        @if($item->prep_time_minutes && $canOrder)
            <span class="badge-prep"><i class="bi bi-clock"></i> {{ $item->prep_time_minutes }} د</span>
        @endif
        @if($hasModifiers && $canOrder)
            <span class="badge-options" title="هذا الصنف فيه خيارات (حجم/إضافات)">
                <i class="bi bi-sliders2"></i> خيارات
            </span>
        @endif
        @if(! $canOrder)
            <div class="badge-unavail">
                <i class="bi {{ $inStock ? 'bi-x-circle' : 'bi-box-seam' }}"></i>
                {{ $unavailReason }}
            </div>
        @endif
    </div>
    <div class="dish-body">
        <h6 class="dish-name">{{ $item->name }}</h6>
        @if($item->description)
            <p class="dish-desc">{{ $item->description }}</p>
        @endif
        @if($ingredients->isNotEmpty())
            {{-- One-line ingredient list. Clamped to two lines via CSS so a
                 long recipe never blows the card height; the full list lives
                 in the item sheet that opens on tap. --}}
            <p class="dish-ingredients" title="مكوّنات الطبق">
                <i class="bi bi-basket2-fill" aria-hidden="true"></i>
                <span>{{ $ingredients->join('، ') }}</span>
            </p>
        @endif
        @if($item->allergens->count())
            <div class="allergens" role="group" aria-label="مسببات حساسية">
                <span class="allergens-label">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    يحتوي على مسببات حساسية:
                </span>
                @foreach($item->allergens as $a)
                    <span class="allergen-chip">{{ $a->icon }} {{ $a->name }}</span>
                @endforeach
            </div>
        @endif

        {{-- Notes were previously inline on every card. Moved to the cart flow
             where users actually need them (per-item notes in cart sheet +
             notes textarea inside the modifier modal). Keeps the menu clean. --}}

        <div class="dish-foot">
            <span class="dish-price">{{ \App\Helpers\Money::format($item->price) }}</span>

            @if(! $canOrder)
                {{-- Unavailable: no + button, just a disabled-looking
                     indicator. Title surfaces the reason (manual toggle
                     vs. out of stock) so the customer + tester know why. --}}
                <span class="dish-unavail-btn"
                      title="{{ $inStock ? 'هذا الصنف غير متوفر حالياً' : 'نفد المخزون من أحد المكونات' }}">
                    <i class="bi {{ $inStock ? 'bi-slash-circle' : 'bi-box-seam' }}"></i>
                </span>
            @else
                {{-- + button (or stepper if already in cart) — only when item is available --}}
                <template x-if="qtyOf({{ $item->id }}) === 0">
                    <button type="button" class="dish-add-fab {{ $hasModifiers ? 'has-mods' : '' }}"
                            @click.stop="onPlus({{ \Illuminate\Support\Js::from($payload) }})"
                            aria-label="{{ $hasModifiers ? 'اختر الخيارات وأضف' : 'أضف للسلة' }}"
                            title="{{ $hasModifiers ? 'اختر الخيارات' : 'أضف للسلة' }}">
                        @if($hasModifiers)
                            <i class="bi bi-sliders2"></i>
                            <span>اختيار</span>
                        @else
                            <i class="bi bi-plus-lg"></i>
                            <span>أضف</span>
                        @endif
                    </button>
                </template>
                <template x-if="qtyOf({{ $item->id }}) > 0">
                    <div class="dish-stepper" @click.stop>
                        <button type="button" @click="onMinus({{ \Illuminate\Support\Js::from($payload) }})" aria-label="ناقص">−</button>
                        <span class="qty" x-text="qtyOf({{ $item->id }})"></span>
                        <button type="button" @click="onPlus({{ \Illuminate\Support\Js::from($payload) }})" aria-label="زيد">+</button>
                    </div>
                </template>
            @endif
        </div>
    </div>
</div>
