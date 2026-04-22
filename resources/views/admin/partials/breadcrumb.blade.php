<!-- breadcrumb -->
@unless(isset($hideBreadcrumb) && $hideBreadcrumb)
<div class="modern-breadcrumb">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb-list">
            {{-- Home Link --}}
            <li class="breadcrumb-item">
                <a href="{{ route('admin.dashboard') }}" class="breadcrumb-link">
                    <i class="bi bi-house-door"></i>
                    <span>لوحة التحكم</span>
                </a>
            </li>

            {{-- Parent Link (if exists) --}}
            @if(isset($breadcrumbParent) && !empty($breadcrumbParent))
                <li class="breadcrumb-separator">
                    <i class="bi bi-chevron-left"></i>
                </li>
                <li class="breadcrumb-item">
                    @if(isset($breadcrumbParentUrl) && !empty($breadcrumbParentUrl))
                        <a href="{{ $breadcrumbParentUrl }}" class="breadcrumb-link">
                            {{ $breadcrumbParent }}
                        </a>
                    @else
                        <span class="breadcrumb-text">{{ $breadcrumbParent }}</span>
                    @endif
                </li>
            @endif

            {{-- Current Page --}}
            @if(isset($breadcrumbTitle) && !empty($breadcrumbTitle))
                <li class="breadcrumb-separator">
                    <i class="bi bi-chevron-left"></i>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <span class="breadcrumb-current">{{ $breadcrumbTitle }}</span>
                </li>
            @endif
        </ol>
    </nav>
</div>
@endunless
<!-- /breadcrumb -->
