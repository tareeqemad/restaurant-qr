{{-- Filters with Search/Clear buttons --}}
@props(['searchId' => 'btnSearch', 'clearId' => 'btnResetFilters', 'searchLabel' => 'بحث', 'clearLabel' => 'تفريغ'])

<div>
    {{ $slot }}

    <div class="d-flex gap-2 mt-3">
        <button class="btn btn-primary" type="button" id="{{ $searchId }}">
            <i class="bi bi-search me-1"></i>{{ $searchLabel }}
        </button>
        <button class="btn btn-outline-secondary" type="button" id="{{ $clearId }}">
            <i class="bi bi-arrow-counterclockwise me-1"></i>{{ $clearLabel }}
        </button>
        @if(isset($extra))
            {{ $extra }}
        @endif
    </div>
</div>
