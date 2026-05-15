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
    if (document.readyState === "complete") {
        hideLoader();
    } else {
        window.addEventListener("load", hideLoader, { once: true });
        // Do not hide early while render-blocking CSS is still being parsed.
        setTimeout(() => {
            if (document.readyState === "complete") hideLoader();
        }, 2500);
        setTimeout(hideLoader, 8000);
    }

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

    function initHorizontalMenuHoverIntent() {
        const html = document.documentElement;
        const menu = document.querySelector(".app-sidebar .main-menu");
        if (!menu || html.getAttribute("data-nav-layout") !== "horizontal") return;

        const isDesktopHover = () => window.innerWidth >= 992 && html.getAttribute("data-toggled") !== "open";
        const closeDelay = 260;

        function closeSiblings(item) {
            const parent = item.parentElement;
            if (!parent) return;

            Array.from(parent.children).forEach(sibling => {
                if (sibling !== item && sibling.classList?.contains("has-sub")) {
                    window.clearTimeout(sibling.__relaxHoverTimer);
                    sibling.classList.remove("relax-hover-open");
                    sibling.classList.remove("relax-pinned-open");
                    sibling.querySelector(":scope > .side-menu__item")?.setAttribute("aria-expanded", "false");
                }
            });
        }

        function closeAllPinned(except) {
            menu.querySelectorAll(".slide.has-sub.relax-pinned-open").forEach(item => {
                if (except && (item === except || item.contains(except) || except.contains(item))) return;
                item.classList.remove("relax-pinned-open");
                if (!item.classList.contains("relax-hover-open") && !item.classList.contains("open")) {
                    item.querySelector(":scope > .side-menu__item")?.setAttribute("aria-expanded", "false");
                }
            });
        }

        function openItem(item) {
            if (!isDesktopHover()) return;
            window.clearTimeout(item.__relaxHoverTimer);
            closeSiblings(item);
            item.classList.add("relax-hover-open");
            item.querySelector(":scope > .side-menu__item")?.setAttribute("aria-expanded", "true");
        }

        function scheduleClose(item) {
            window.clearTimeout(item.__relaxHoverTimer);
            item.__relaxHoverTimer = window.setTimeout(() => {
                item.classList.remove("relax-hover-open");
                if (!item.classList.contains("open")) {
                    item.querySelector(":scope > .side-menu__item")?.setAttribute("aria-expanded", "false");
                }
            }, closeDelay);
        }

        menu.querySelectorAll(".slide.has-sub").forEach(item => {
            if (item.dataset.relaxHoverBound === "1") return;
            item.dataset.relaxHoverBound = "1";

            item.addEventListener("mouseenter", () => openItem(item));
            item.addEventListener("mouseleave", () => scheduleClose(item));
            item.addEventListener("focusin", () => openItem(item));
            item.addEventListener("focusout", event => {
                if (!item.contains(event.relatedTarget)) scheduleClose(item);
            });

            item.querySelector(":scope > .side-menu__item")?.addEventListener("click", event => {
                if (!isDesktopHover()) return;

                event.preventDefault();
                event.stopPropagation();
                window.clearTimeout(item.__relaxHoverTimer);
                closeSiblings(item);
                item.classList.toggle("relax-pinned-open");
                item.classList.add("relax-hover-open");
                item.querySelector(":scope > .side-menu__item")?.setAttribute(
                    "aria-expanded",
                    item.classList.contains("relax-pinned-open") ? "true" : "false"
                );
            });
        });

        if (!menu.dataset.relaxOutsideBound) {
            menu.dataset.relaxOutsideBound = "1";

            document.addEventListener("click", event => {
                if (!menu.contains(event.target)) closeAllPinned();
            });

            document.addEventListener("keydown", event => {
                if (event.key === "Escape") closeAllPinned();
            });
        }
    }

    function syncHorizontalMenuArrows() {
        const html = document.documentElement;
        if (html.getAttribute("data-nav-layout") !== "horizontal") return;

        const menu = document.querySelector(".app-sidebar .main-menu");
        const shell = document.querySelector(".app-sidebar .main-sidebar");
        const left = document.getElementById("slide-left");
        const right = document.getElementById("slide-right");
        if (!menu || !shell || !left || !right) return;

        if (window.innerWidth < 992 || html.getAttribute("data-toggled") === "open") {
            left.classList.add("d-none");
            right.classList.add("d-none");
            return;
        }

        const hasOverflow = menu.scrollWidth > shell.clientWidth + 8;
        left.classList.toggle("d-none", !hasOverflow);
        right.classList.toggle("d-none", !hasOverflow);

        if (!hasOverflow) {
            menu.style.marginLeft = "0px";
            menu.style.marginRight = "0px";
        }
    }

    let horizontalMenuResizeTimer;
    function queueHorizontalMenuArrowSync() {
        window.clearTimeout(horizontalMenuResizeTimer);
        horizontalMenuResizeTimer = window.setTimeout(syncHorizontalMenuArrows, 80);
    }

    document.addEventListener("DOMContentLoaded", queueHorizontalMenuArrowSync);
    window.addEventListener("load", queueHorizontalMenuArrowSync);
    window.addEventListener("resize", queueHorizontalMenuArrowSync);
    document.addEventListener("livewire:navigated", queueHorizontalMenuArrowSync);
    document.addEventListener("DOMContentLoaded", initHorizontalMenuHoverIntent);
    window.addEventListener("load", initHorizontalMenuHoverIntent);
    document.addEventListener("livewire:navigated", initHorizontalMenuHoverIntent);
})();
