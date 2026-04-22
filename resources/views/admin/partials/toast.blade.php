<style>
    /* تحسين تصميم Toast للرسائل الحمراء */
    #appToast.text-bg-danger {
        background-color: #dc3545 !important;
        color: #fff !important;
        border-left: 4px solid #b02a37 !important;
        box-shadow: 0 0.5rem 1rem rgba(220, 53, 69, 0.3) !important;
    }
    
    #appToast.text-bg-danger .toast-body {
        font-weight: 500;
        padding: 0.75rem 1rem;
    }
    
    #appToast.text-bg-danger .btn-close-white {
        filter: invert(1) grayscale(100%) brightness(200%);
    }
    
    /* تحسين التصميم العام للـ Toast */
    #appToast {
        border-radius: 0.5rem;
        font-size: 0.95rem;
    }
    
    #appToast .toast-body {
        display: flex;
        align-items: center;
    }
</style>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1085;">
    <div id="appToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="appToastBody"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
    (function () {
        window.showToast = function (message, variant = 'success', delay = 3500) {
            const toastEl = document.getElementById('appToast');
            const bodyEl  = document.getElementById('appToastBody');
            if (!toastEl || !bodyEl) return;

            // تحويل 'error' إلى 'danger' للتوافق مع Bootstrap
            if (variant === 'error') {
                variant = 'danger';
            }

            // إضافة أيقونة حسب النوع
            const icons = {
                'success': '<i class="bi bi-check-circle-fill me-2 fs-5"></i>',
                'danger': '<i class="bi bi-x-circle-fill me-2 fs-5"></i>',
                'warning': '<i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>',
                'info': '<i class="bi bi-info-circle-fill me-2 fs-5"></i>',
                'primary': '<i class="bi bi-info-circle-fill me-2 fs-5"></i>'
            };

            const icon = icons[variant] || '';
            
            // إضافة shadow و padding أفضل للتصميم
            toastEl.className = `toast align-items-center text-bg-${variant} border-0 shadow-lg`;
            toastEl.style.minWidth = '300px';
            bodyEl.innerHTML = icon + (message || '');

            const closeBtn = toastEl.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.classList.remove('btn-close-white');
                if (['primary','success','danger','dark'].includes(variant)) {
                    closeBtn.classList.add('btn-close-white');
                }
            }

            const t = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: parseInt(delay, 10) || 3500 });
            t.show();
        };
    })();
</script>

{{-- Session Messages --}}
@if(session('success'))
    <script>
        document.addEventListener('DOMContentLoaded', () => showToast(@json(session('success')), 'success'));
    </script>
@endif

@if(session('error'))
    <script>
        document.addEventListener('DOMContentLoaded', () => showToast(@json(session('error')), 'danger'));
    </script>
@endif

{{-- Validation Errors (أول خطأ) --}}
@if(isset($errors) && $errors->any())
    <script>
        document.addEventListener('DOMContentLoaded', () => showToast(@json($errors->first()), 'danger', 5000));
    </script>
@endif
