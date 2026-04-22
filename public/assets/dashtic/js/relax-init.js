/* Minimal replacement for Dashtic's custom.js.
   Dashtic's custom.js (513 lines) includes color-picker init, type-ahead
   search, fullscreen helpers, tooltip/popover init, theme toggles, and
   switcher callbacks — most of which depend on DOM elements that only
   exist inside their switcher offcanvas (which we intentionally skipped).
   This file ships ONLY the pieces we actually use: loader hide +
   Bootstrap tooltip/popover init + fullscreen helper. */
/* ─── Dashtic function stubs ─── MUST be at global scope (defined outside
   the IIFE below) because defaultmenu.min.js calls horizontalClickFn() and
   checkOptions() without qualification. They're normally provided by
   custom-switcher.min.js (which we don't load because it requires the
   switcher offcanvas DOM). Our stubs only do what's strictly needed for the
   horizontal nav layout to work. */
function horizontalClickFn() {
    const html = document.querySelector('html');
    html.setAttribute('data-nav-layout', 'horizontal');
    html.removeAttribute('data-vertical-style');
    if (!html.getAttribute('data-nav-style')) {
        html.setAttribute('data-nav-style', 'menu-click');
    }
    // checkHoriMenu IS defined in defaultmenu.min.js — call it so the menu
    // finishes its horizontal-layout setup (scroll arrows, etc).
    if (typeof checkHoriMenu === 'function') {
        try { checkHoriMenu(); } catch (e) {}
    }
}

function verticalFn() {
    const html = document.querySelector('html');
    html.setAttribute('data-nav-layout', 'vertical');
    html.removeAttribute('data-nav-style');
}

/* No-op — original lives in custom-switcher and just syncs radio buttons
   inside the switcher offcanvas, which we don't render. */
function checkOptions() {}

(function () {
    "use strict";

    /* ─── Page loader ─── */
    function hideLoader() {
        const loader = document.getElementById("loader");
        if (loader) loader.classList.add("d-none");
    }
    window.addEventListener("load", hideLoader);
    // Safety net in case load event already fired / slow images
    setTimeout(hideLoader, 1200);

    /* ─── Bootstrap tooltips & popovers ─── */
    document.addEventListener("DOMContentLoaded", function () {
        if (!window.bootstrap) return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            try { new bootstrap.Tooltip(el); } catch (e) {}
        });
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
            try { new bootstrap.Popover(el); } catch (e) {}
        });
    });

    /* ─── Fullscreen helper (used by header toggle button) ─── */
    window.openFullscreen = window.openFullscreen || function () {
        const openIcon  = document.querySelector(".full-screen-open");
        const closeIcon = document.querySelector(".full-screen-close");
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen?.();
            openIcon?.classList.add("d-none");
            closeIcon?.classList.remove("d-none");
        } else {
            document.exitFullscreen?.();
            openIcon?.classList.remove("d-none");
            closeIcon?.classList.add("d-none");
        }
    };

    /* ─── Cover images (rare but supported) ─── */
    document.querySelectorAll(".cover-image").forEach(ele => {
        const attr = ele.getAttribute("data-bs-image-src");
        if (attr) ele.style.background = `url(${attr}) center center`;
    });

    /* ─── Scroll-to-top button ─── */
    const scrollBtn = document.querySelector(".scrollToTop");
    if (scrollBtn) {
        window.addEventListener("scroll", () => {
            scrollBtn.classList.toggle("show", window.scrollY > 280);
        });
        scrollBtn.addEventListener("click", () => {
            window.scrollTo({ top: 0, behavior: "smooth" });
        });
    }

    /* ─── Dropdown item close handlers (cart / notifications) ─── */
    document.querySelectorAll(".dropdown-item-close, .dropdown-item-close1").forEach(btn => {
        btn.addEventListener("click", e => {
            e.stopPropagation();
            const item = btn.closest(".dropdown-item");
            if (item) item.remove();
        });
    });
})();
