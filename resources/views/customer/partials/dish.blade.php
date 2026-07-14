@php
    // Ingredient names from the recipe — only those with an ingredient row
    // (defensive: a recipe line could exist whose ingredient was deleted).
    // Optional lines are still listed so the diner can see everything the
    // dish "may contain", which matches how the kitchen prep card reads.
    $ingredients = $item->relationLoaded('recipeItems')
        ? $item->recipeItems
            ->map(fn ($r) => $r->ingredient?->localizedName())
            ->filter()
            ->values()
        : collect();

    // Live availability: BOTH the manual `is_available` flag AND a real-time
    // stock check (recursively expanding composite ingredients). Different
    // messages tell the diner whether this is an operator toggle or stock.
    $manuallyAvailable = (bool) $item->is_available;
    $shortages         = $manuallyAvailable ? $item->stockShortages(1.0) : [];
    $inStock           = empty($shortages);
    $canOrder          = $manuallyAvailable && $inStock;
    $unavailReason     = ! $manuallyAvailable
        ? __('ui.dish.not_available')
        : (! $inStock ? __('ui.dish.out_of_stock') : null);

    // Promotion lookup. If the item has a live promo, we render a
    // strikethrough on the menu price + a discount badge. The cart
    // still uses effectivePrice() — never the raw price — so the
    // amount the diner pays matches what the badge promised.
    $promo          = $item->activePromotion();
    $effectivePrice = $promo ? $promo->applyTo((float) $item->price) : (float) $item->price;
    $discountPct    = $promo ? $item->discountPct() : null;
    $itemName = $item->localizedName();
    $itemDescription = $item->localizedDescription();
    $payload = [
        'id' => $item->id,
        'name' => $itemName,
        'description' => $itemDescription,
        'price' => $effectivePrice,
        'original_price' => $promo ? (float) $item->price : null,
        'image' => $item->imageUrl(),
        'ingredients' => $ingredients->all(),
        'has_modifiers' => $item->modifierGroups->count() > 0,
        'modifier_groups' => $item->modifierGroups->map(fn($g) => [
            'id' => $g->id,
            'name' => $g->localizedName(),
            'min_select' => $g->min_select,
            'max_select' => $g->max_select,
            'required' => (bool) $g->required,
            'modifiers' => $g->modifiers->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->localizedName(),
                'price_delta' => (float) $m->price_delta,
            ])->values()->toArray(),
        ])->values()->toArray(),
    ];
    $hasModifiers = $item->modifierGroups->count() > 0;
    $searchText = trim(collect([
        $itemName,
        $itemDescription,
        $item->allergens->map(fn ($allergen) => $allergen->localizedName())->join(' '),
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
        <img src="{{ $item->imageUrl() }}" alt="{{ $itemName }}" loading="lazy" data-dish-img="{{ $item->id }}"
             onerror="window.dishImgFallback && dishImgFallback(this)">
        @if($item->is_featured && $canOrder)
            <span class="badge-today">{{ __('ui.dish.available_today') }}</span>
        @endif
        @if($item->prep_time_minutes && $canOrder)
            <span class="badge-prep"><i class="bi bi-clock"></i> {{ __('ui.dish.minutes_short', ['count' => $item->prep_time_minutes]) }}</span>
        @endif
        @if($promo && $canOrder)
            <span class="badge-promo" title="{{ $promo->name }}">
                <i class="bi bi-tag-fill"></i>
                @if($discountPct)
                    {{ __('ui.dish.discount_percent', ['percent' => rtrim(rtrim((string) $discountPct, '0'), '.')]) }}
                @else
                    {{ __('ui.dish.offer') }}
                @endif
            </span>
        @endif
        @if($hasModifiers && $canOrder)
            <span class="badge-options" title="{{ __('ui.dish.has_options_title') }}">
                <i class="bi bi-sliders2"></i> {{ __('ui.dish.options') }}
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
        <h6 class="dish-name">{{ $itemName }}</h6>
        @if($itemDescription)
            <p class="dish-desc">{{ $itemDescription }}</p>
        @endif
        @if($ingredients->isNotEmpty())
            {{-- One-line ingredient list. Clamped to two lines via CSS so a
                 long recipe never blows the card height; the full list lives
                 in the item sheet that opens on tap. --}}
            <p class="dish-ingredients" title="{{ __('ui.dish.ingredients_title') }}">
                <i class="bi bi-basket2-fill" aria-hidden="true"></i>
            <span>{{ $ingredients->join(', ') }}</span>
            </p>
        @endif
        @if($item->allergens->count())
            <div class="allergens" role="group" aria-label="{{ __('ui.dish.allergens_label') }}">
                <span class="allergens-label">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    {{ __('ui.dish.allergens_label') }}
                </span>
                @foreach($item->allergens as $a)
                    <span class="allergen-chip">{{ $a->icon }} {{ $a->localizedName() }}</span>
                @endforeach
            </div>
        @endif

        {{-- Notes were previously inline on every card. Moved to the cart flow
             where users actually need them (per-item notes in cart sheet +
             notes textarea inside the modifier modal). Keeps the menu clean. --}}

        <div class="dish-foot">
            @if($promo)
                <div class="dish-price-wrap">
                    <span class="dish-price-old">{{ \App\Helpers\Money::format($item->price) }}</span>
                    <span class="dish-price dish-price-new">{{ \App\Helpers\Money::format($effectivePrice) }}</span>
                </div>
            @else
                <span class="dish-price">{{ \App\Helpers\Money::format($item->price) }}</span>
            @endif

            @if(! $canOrder)
                {{-- Unavailable: no + button, just a disabled-looking
                     indicator. Title surfaces the reason (manual toggle
                     vs. out of stock) so the customer + tester know why. --}}
                <span class="dish-unavail-btn"
                      title="{{ $inStock ? __('ui.dish.manual_unavailable_title') : __('ui.dish.stock_unavailable_title') }}">
                    <i class="bi {{ $inStock ? 'bi-slash-circle' : 'bi-box-seam' }}"></i>
                </span>
            @else
                {{-- + button (or stepper if already in cart) — only when item is available --}}
                <template x-if="qtyOf({{ $item->id }}) === 0">
                    <button type="button" class="dish-add-fab {{ $hasModifiers ? 'has-mods' : '' }}"
                            @click.stop="onPlus({{ \Illuminate\Support\Js::from($payload) }})"
                            aria-label="{{ $hasModifiers ? __('ui.dish.choose_options_add') : __('ui.dish.add_to_cart') }}"
                            title="{{ $hasModifiers ? __('ui.dish.choose_options') : __('ui.dish.add_to_cart') }}">
                        @if($hasModifiers)
                            <i class="bi bi-sliders2"></i>
                            <span>{{ __('ui.dish.choose') }}</span>
                        @else
                            <i class="bi bi-plus-lg"></i>
                            <span>{{ __('ui.dish.add') }}</span>
                        @endif
                    </button>
                </template>
                <template x-if="qtyOf({{ $item->id }}) > 0">
                    <div class="dish-stepper" @click.stop>
                        <button type="button" @click="onMinus({{ \Illuminate\Support\Js::from($payload) }})" aria-label="{{ __('ui.dish.minus') }}">−</button>
                        <span class="qty" x-text="qtyOf({{ $item->id }})"></span>
                        <button type="button" @click="onPlus({{ \Illuminate\Support\Js::from($payload) }})" aria-label="{{ __('ui.dish.plus') }}">+</button>
                    </div>
                </template>
            @endif
        </div>
    </div>
</div>
