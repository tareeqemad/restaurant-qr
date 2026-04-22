{{-- Card Header (Index Pages) - with top border accent --}}
@props(['title', 'icon' => null])

<div {{ $attributes->merge(['class' => 'general-card-header']) }}>
    <div>
        <h5 class="general-title">
            @if($icon)<i class="bi {{ $icon }} me-2"></i>@endif
            {{ $title }}
        </h5>
    </div>
    @if(isset($actions))
        <div class="d-flex gap-2 flex-wrap align-items-center">
            {{ $actions }}
        </div>
    @endif
    @if($slot->isNotEmpty())
        <div class="w-100">
            {{ $slot }}
        </div>
    @endif
</div>
