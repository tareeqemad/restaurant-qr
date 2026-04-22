{{-- Card Header (Form/Show Pages) - with side border accent --}}
@props(['title', 'icon' => null, 'backRoute' => null, 'backLabel' => 'رجوع'])

<div class="card-header-form">
    <h5 class="general-title">
        @if($icon)<i class="bi {{ $icon }} me-2"></i>@endif
        {{ $title }}
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        {{ $slot }}
        @if($backRoute)
            <a href="{{ $backRoute }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-right me-1"></i>{{ $backLabel }}
            </a>
        @endif
    </div>
</div>
