{{-- Loading Skeleton --}}
@props(['type' => 'card', 'lines' => 3])

<div {{ $attributes->merge(['class' => 'skeleton-loader skeleton-' . $type]) }}>
    @if($type === 'table')
        <div class="skeleton-line skeleton-line-header"></div>
        @for($i = 0; $i < $lines; $i++)
            <div class="skeleton-line skeleton-line-row"></div>
        @endfor
    @elseif($type === 'chart')
        <div class="skeleton-chart-area"></div>
    @else
        @for($i = 0; $i < $lines; $i++)
            <div class="skeleton-line" style="width: {{ $i === 0 ? '80%' : ($i === $lines - 1 ? '40%' : '60%') }}"></div>
        @endfor
    @endif
</div>
