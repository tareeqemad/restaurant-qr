{{--
  Page header + breadcrumb — copied 1:1 from Dashtic's empty.html pattern.

  Dashtic's canonical structure (Root:html/empty.html):
    <div class="page-header">
        <h1 class="page-title my-auto">TITLE</h1>
        <div class="page-header-bredcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="..."><svg .../> <span>Home</span></a></li>
                <li class="breadcrumb-item"><a href="...">Parent</a></li>
                <li class="breadcrumb-item active" aria-current="page">Current</li>
            </ol>
        </div>
    </div>

  Props:
    title     Main heading (required)
    icon     (kept for back-compat, unused in Dashtic's native style)
    subtitle (kept for back-compat, unused in Dashtic's native style)
    crumbs   Intermediate links — accepts both formats:
               - [ ['label' => '...', 'url' => '...'], ... ]
               - [ 'Categories' => route(...), 'Child' => null ]
    home     Prepend a home link with house icon. Default true.
  Slot:
    actions  Optional right-hand buttons (rendered on the opposite side of the title).
--}}

@props([
    'title'    => null,
    'icon'     => null,
    'subtitle' => null,
    'crumbs'   => [],
    'home'     => true,
])

@php
    $trail = [];
    foreach ($crumbs as $key => $value) {
        if (is_array($value)) {
            $trail[] = [
                'label' => $value['label'] ?? (is_string($key) ? $key : ''),
                'url'   => $value['url']   ?? null,
            ];
        } elseif (is_string($key)) {
            $trail[] = ['label' => $key, 'url' => $value];
        } elseif (is_string($value)) {
            $trail[] = ['label' => $value, 'url' => null];
        }
    }
@endphp

<div {{ $attributes->merge(['class' => 'page-header']) }}>
    <div class="relax-page-main">
        @if($icon)
            @php
                // Accept icons from any included font (bi-*, ri-*, fe-*). For
                // legacy callers that pass just "fire" without a prefix we
                // still assume Bootstrap Icons.
                $iconClass = str_contains($icon, '-') ? $icon : ('bi-'.$icon);
            @endphp
            <span class="relax-page-icon" aria-hidden="true">
                <i class="{{ $iconClass }}"></i>
            </span>
        @endif

        <div class="relax-page-copy">
            @if($title)
                <h1 class="page-title my-auto">{{ $title }}</h1>
            @endif

            @if($subtitle)
                <p class="relax-page-subtitle">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    <div class="relax-page-side">
        <div class="page-header-bredcrumb d-flex align-items-center gap-3">
            <ol class="breadcrumb mb-0">
                @if($home)
                    <li class="breadcrumb-item">
                        <a href="{{ route('admin.dashboard') }}">
                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 0 24 24" width="24">
                                <path d="M0 0h24v24H0V0z" fill="none"></path>
                                <path d="M12 3L2 12h3v8h6v-6h2v6h6v-8h3L12 3zm5 15h-2v-6H9v6H7v-7.81l5-4.5 5 4.5V18z"></path>
                                <path d="M7 10.19V18h2v-6h6v6h2v-7.81l-5-4.5z" opacity=".3"></path>
                            </svg>
                            <span class="breadcrumb-icon"> {{ __('admin.common.home') }}</span>
                        </a>
                    </li>
                @endif

                @foreach($trail as $crumb)
                    @if(!empty($crumb['url']))
                        <li class="breadcrumb-item">
                            <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                        </li>
                    @else
                        <li class="breadcrumb-item">{{ $crumb['label'] }}</li>
                    @endif
                @endforeach

                @if($title)
                    <li class="breadcrumb-item active" aria-current="page">{{ $title }}</li>
                @endif
            </ol>
        </div>

        @isset($actions)
            <div class="relax-page-actions">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
