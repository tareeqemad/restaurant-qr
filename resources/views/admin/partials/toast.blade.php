{{--
    SweetAlert2-driven notifications + form-confirm interceptor.

    Replaces both the legacy Bootstrap toast and the native window.confirm
    dialogs sprinkled across `onsubmit="return confirm('...')"` forms.

    Surfaces:
      • window.showToast(message, variant)         — top-end toast
      • flash messages (success / error / errors)  — auto-fire on page load
      • forms with onsubmit containing confirm(…)  — auto-upgrade to Swal modal
      • elements with data-confirm="…"             — opt-in Swal modal on click
--}}
<script>
(function () {
    if (! window.Swal) return; // CDN failure — degrade silently rather than break.

    // ─── Toast helper ─────────────────────────────────────────────────
    // Position: top-start → top-left in RTL (المستخدم طلبها على اليسار).
    // Custom compact design with our own classes — overrides SwAl defaults
    // to a slim pill rather than the bulky default popup.
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-start',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        customClass: {
            popup: 'app-toast',
            title: 'app-toast__title',
            timerProgressBar: 'app-toast__bar',
        },
        showClass: { popup: 'app-toast--show' },
        hideClass: { popup: 'app-toast--hide' },
        didOpen: (t) => {
            t.addEventListener('mouseenter', Swal.stopTimer);
            t.addEventListener('mouseleave', Swal.resumeTimer);
        },
    });

    const variantToIcon = (v) => {
        if (v === 'danger' || v === 'error') return 'error';
        if (v === 'warning') return 'warning';
        if (v === 'info' || v === 'primary') return 'info';
        return 'success';
    };

    window.showToast = function (message, variant = 'success', delay = 3500) {
        if (! message) return;
        Toast.fire({
            icon: variantToIcon(variant),
            title: message,
            timer: parseInt(delay, 10) || 3500,
        });
    };

    // Backward compat: showNotification used by Livewire bridges.
    window.showNotification = function (title, body, variant = 'info') {
        Toast.fire({
            icon: variantToIcon(variant),
            title: title || body || '',
            text: title && body ? body : '',
        });
        if (typeof window.playNotify === 'function') window.playNotify();
    };

    // ─── Confirm modal helper ─────────────────────────────────────────
    window.confirmAction = function ({ title, text, confirmText, cancelText, icon } = {}) {
        return Swal.fire({
            title: title || 'تأكيد العملية',
            text:  text  || '',
            icon:  icon  || 'warning',
            showCancelButton: true,
            confirmButtonText: confirmText || 'نعم، تأكيد',
            cancelButtonText:  cancelText  || 'إلغاء',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            reverseButtons: true,
            focusCancel: true,
        }).then(r => r.isConfirmed);
    };

    // ─── Form-confirm interceptor ─────────────────────────────────────
    // Captures legacy `onsubmit="return confirm('...')"` markup and
    // upgrades it to a Swal modal — no view edits required. Forms that
    // already opted into the new pattern via `data-confirm` work too.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (! form || form.tagName !== 'FORM') return;
        if (form.dataset.confirmHandled === '1') return; // already approved

        let message = form.dataset.confirm || null;

        // Extract the message from `onsubmit="return confirm('…')"` if no
        // explicit data-confirm. We strip the attribute after extraction so
        // it doesn't fire the native dialog on the programmatic resubmit.
        if (! message) {
            const onsubmit = form.getAttribute('onsubmit');
            const match = onsubmit && onsubmit.match(/confirm\(\s*['"`]([^'"`]+)['"`]\s*\)/);
            if (match) {
                message = match[1];
                form.removeAttribute('onsubmit');
            }
        }

        if (! message) return; // No confirmation needed.

        e.preventDefault();
        window.confirmAction({ text: message }).then(ok => {
            if (! ok) return;
            form.dataset.confirmHandled = '1';
            // Some forms use buttons of name="…"; requestSubmit preserves
            // which button was clicked when supported.
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }, true); // capture phase — beat any other submit listener

    // ─── Click-confirm for non-form actions ────────────────────────────
    // Use on buttons/links that aren't inside a form: <a data-confirm="…">.
    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-confirm]');
        if (! el || el.tagName === 'BUTTON' || el.closest('form')) return; // forms handled above
        if (el.dataset.confirmHandled === '1') return;
        e.preventDefault();
        window.confirmAction({ text: el.dataset.confirm }).then(ok => {
            if (! ok) return;
            el.dataset.confirmHandled = '1';
            el.click();
        });
    }, true);
})();
</script>

<style>
    /* ─── Compact app toast — slim pill on top-start (left in RTL) ──────
       Overrides SwAl2's default toast box with our brand styling.
       Width is content-sized with a sane max so short messages stay tight. */
    .swal2-container.swal2-top-start { padding: 14px 18px !important; }

    .swal2-popup.swal2-toast.app-toast {
        font-family: inherit !important;
        padding: 10px 14px !important;
        min-width: 0 !important;
        max-width: 360px !important;
        width: auto !important;
        border-radius: 12px !important;
        background: #fff !important;
        box-shadow: 0 6px 24px -8px rgba(15, 71, 49, .25),
                    0 2px 6px rgba(15, 23, 42, .06) !important;
        border: 1px solid rgba(15, 71, 49, .08) !important;
        overflow: hidden !important;
    }
    /* ─── Icon — uniform 22px circle for ALL types ────────────────────
       Strategy: SwAl's defaults are 80px with absolute-positioned inner
       pieces (rings, lines, SVGs). Fighting each piece individually is a
       trap — the success ✓ kept overflowing because its two skewed lines
       carry hardcoded coordinates that don't scale. So we go nuclear:
       hide EVERY inner piece, then draw the entire icon (circle + glyph)
       ourselves with two pseudo-elements:
         ::before → the circular ring (color via currentColor)
         ::after  → a single Unicode glyph centered in the circle
       Same recipe for all 4 types — only the glyph + color differ. */
    .swal2-popup.swal2-toast.app-toast .swal2-icon {
        width: 22px !important;
        height: 22px !important;
        min-width: 22px !important;
        margin: 0 0 0 10px !important;
        border: 0 !important;
        padding: 0 !important;
        position: relative;
        flex-shrink: 0;
        background: transparent !important;
        transform: none !important;
        overflow: hidden;
    }

    /* Outer ring */
    .swal2-popup.swal2-toast.app-toast .swal2-icon::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 1.5px solid currentColor;
        box-sizing: border-box;
    }

    /* Centered glyph — overridden per icon type below */
    .swal2-popup.swal2-toast.app-toast .swal2-icon::after {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        font-weight: 900;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, sans-serif;
    }

    /* Hide every inner piece SwAl ships — we draw nothing from them */
    .swal2-popup.swal2-toast.app-toast .swal2-icon > * {
        display: none !important;
    }

    /* Per-type color + glyph */
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-success { color: #16a34a !important; }
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-success::after {
        content: '\2713';            /* ✓ check mark */
        color: #16a34a;
        font-size: 13px;
    }

    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-error { color: #dc2626 !important; }
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-error::after {
        content: '\00d7';            /* × multiplication sign */
        color: #dc2626;
        font-size: 16px;
        margin-top: -1px;            /* visual centering tweak — × sits low */
    }

    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-warning { color: #f59e0b !important; }
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-warning::after {
        content: '!';
        color: #f59e0b;
        font-size: 13px;
    }

    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-info,
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-question { color: #0ea5e9 !important; }
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-info::after,
    .swal2-popup.swal2-toast.app-toast .swal2-icon.swal2-question::after {
        content: 'i';
        color: #0ea5e9;
        font-size: 12px;
        font-style: italic;
        font-family: 'Times New Roman', serif;
    }

    .app-toast__title {
        font-size: .85rem !important;
        font-weight: 600 !important;
        color: #0f172a !important;
        line-height: 1.45 !important;
        padding: 0 !important;
        margin: 0 !important;
        text-align: start !important;
    }
    .app-toast__bar {
        background: linear-gradient(90deg, #16a34a, #0f4731) !important;
        height: 2px !important;
    }

    /* Variant tints — left accent strip per icon type */
    .swal2-popup.swal2-toast.app-toast.swal2-icon-success { border-inline-start: 3px solid #16a34a !important; }
    .swal2-popup.swal2-toast.app-toast.swal2-icon-error   { border-inline-start: 3px solid #dc2626 !important; }
    .swal2-popup.swal2-toast.app-toast.swal2-icon-warning { border-inline-start: 3px solid #f59e0b !important; }
    .swal2-popup.swal2-toast.app-toast.swal2-icon-info    { border-inline-start: 3px solid #0ea5e9 !important; }

    /* Smooth slide-in from the start side (left in RTL) */
    @keyframes appToastIn  {
        from { opacity: 0; transform: translateX(20px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    @keyframes appToastOut {
        from { opacity: 1; transform: translateX(0); }
        to   { opacity: 0; transform: translateX(20px); }
    }
    .app-toast--show { animation: appToastIn  .25s ease-out both; }
    .app-toast--hide { animation: appToastOut .2s  ease-in  both; }

    /* Modal confirm buttons — kept minimal */
    .swal2-styled.swal2-confirm,
    .swal2-styled.swal2-cancel { box-shadow: none !important; font-weight: 700 !important; }
</style>

{{-- Flash messages — auto-fire on page render --}}
@if(session('success'))
    <script>document.addEventListener('DOMContentLoaded', () => window.showToast(@json(session('success')), 'success'));</script>
@endif

@if(session('error'))
    <script>document.addEventListener('DOMContentLoaded', () => window.showToast(@json(session('error')), 'error', 5500));</script>
@endif

@if(session('warning'))
    <script>document.addEventListener('DOMContentLoaded', () => window.showToast(@json(session('warning')), 'warning', 4500));</script>
@endif

@if(session('info'))
    <script>document.addEventListener('DOMContentLoaded', () => window.showToast(@json(session('info')), 'info'));</script>
@endif

@if(isset($errors) && $errors->any())
    <script>document.addEventListener('DOMContentLoaded', () => window.showToast(@json($errors->first()), 'error', 6000));</script>
@endif
