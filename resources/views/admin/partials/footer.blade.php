@php
    $siteName = \App\Helpers\Brand::name();
@endphp
<footer class="footer mt-auto py-3 bg-white text-center border-top">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-center align-items-center gap-2">
            <span class="d-inline-flex align-items-center gap-1">
                <i class="bi bi-cup-hot-fill text-danger"></i>
                <span>
                    © {{ date('Y') }}
                    <span class="text-primary fw-semibold">{{ $siteName }}</span>
                    - {{ __('admin.common.all_rights_reserved') }}
                </span>
            </span>
            <span class="text-muted">|</span>
            <span class="badge bg-primary">
                <i class="bi bi-box-seam me-1"></i>
                v1.0.0
            </span>
        </div>
    </div>
</footer>
