{{--
  Page wrapper — outputs Dashtic's NATIVE card structure:

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">TITLE <span class="badge">N</span></h3>
            <div class="card-options"> ACTIONS </div>
        </div>
        <div class="card-body data-panel-filters"> FILTERS </div>   (optional)
        <div class="card-body"> DEFAULT SLOT </div>
        <div class="card-footer"> FOOTER </div>                     (optional)
    </div>

  All 32 pages that use <x-admin.data-panel> automatically adopt
  Dashtic's card styles (shadow, header border, spacing) without any
  per-page edit.

  Props:
    title   string      Heading (rendered as .card-title h3)
    count   int|null    Shown as a pill next to the title (e.g. "12 سجل")
    icon    string|null Bootstrap Icons class, e.g. "bi-people"
    compact bool        Tighten card-body padding (for dense tables)

  Slots:
    filters  Filter form — rendered in its own .card-body with a divider
    actions  Toolbar buttons on the left (export, new, bulk ops)
    footer   Bottom bar (pagination etc.)
    default  Main body (table, cards, grid…)
--}}

@props([
    'title'   => null,
    'count'   => null,
    'icon'    => null,
    'compact' => false,
])

<div {{ $attributes->merge(['class' => 'card custom-card data-panel']) }}>

    @if($title || isset($actions))
        <div class="card-header data-panel-header">
            @if($title)
                <h3 class="card-title data-panel-title mb-0 d-inline-flex align-items-center gap-2">
                    @if($icon)
                        <i class="bi {{ $icon }} text-accent"></i>
                    @endif
                    <span>{{ $title }}</span>
                    @if(!is_null($count))
                        <span class="badge bg-primary-transparent ms-1">{{ $count }}</span>
                    @endif
                </h3>
            @endif

            @isset($actions)
                <div class="card-options data-panel-actions">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    @isset($filters)
        <div class="card-body data-panel-filters border-block-end bg-light p-3">
            {{ $filters }}
        </div>
    @endisset

    <div class="card-body {{ $compact ? 'p-3' : '' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="card-footer">
            {{ $footer }}
        </div>
    @endisset
</div>
