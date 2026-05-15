(function () {
    'use strict';

    const lockedForms = new Set();
    const formStates = new WeakMap();
    let lastSubmitter = null;
    let lastSubmitterAt = 0;

    const SUBMIT_SELECTOR = [
        'button[type="submit"]',
        'button:not([type])',
        'input[type="submit"]',
        'input[type="image"]',
    ].join(',');

    function injectStyles() {
        if (document.getElementById('relax-submit-lock-styles')) return;

        const style = document.createElement('style');
        style.id = 'relax-submit-lock-styles';
        style.textContent = `
            @keyframes relax-submit-lock-spin { to { transform: rotate(360deg); } }
            .submit-lock-spinner {
                width: 1em;
                height: 1em;
                border: .16em solid currentColor;
                border-inline-end-color: transparent;
                border-radius: 999px;
                display: inline-block;
                flex: 0 0 auto;
                animation: relax-submit-lock-spin .75s linear infinite;
            }
            .is-submit-locked {
                cursor: progress !important;
                pointer-events: none;
            }
            button.is-submit-locked,
            .btn.is-submit-locked,
            .pf-btn.is-submit-locked,
            .btn-submit.is-submit-locked {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: .45rem;
                opacity: .78;
                white-space: nowrap;
            }
        `;
        document.head.appendChild(style);
    }

    function isSubmitControl(element) {
        if (! element || ! element.matches) return false;
        return element.matches(SUBMIT_SELECTOR);
    }

    function isOptedOut(form, submitter) {
        if (! form || ! form.matches) return true;
        if (form.matches('[data-no-submit-lock], [data-submit-lock="off"]')) return true;
        if (submitter && submitter.matches && submitter.matches('[data-no-submit-lock], [data-submit-lock="off"]')) return true;
        if (submitter && String(submitter.getAttribute('formmethod') || '').toLowerCase() === 'dialog') return true;
        return false;
    }

    function escapeCss(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function collectSubmitControls(form) {
        const controls = new Set(Array.from(form.querySelectorAll(SUBMIT_SELECTOR)));

        if (form.id) {
            const id = escapeCss(form.id);
            const externalSelector = [
                `button[type="submit"][form="${id}"]`,
                `button:not([type])[form="${id}"]`,
                `input[type="submit"][form="${id}"]`,
                `input[type="image"][form="${id}"]`,
            ].join(',');

            document.querySelectorAll(externalSelector).forEach((control) => controls.add(control));
        }

        return Array.from(controls);
    }

    function getControlText(control) {
        if (! control) return '';
        if (control.tagName === 'INPUT') return control.value || '';
        return (control.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function getBusyText(form, submitter) {
        const explicit = submitter?.dataset?.loadingText
            || submitter?.dataset?.busyText
            || form.dataset.loadingText
            || form.dataset.busyText;

        if (explicit) return explicit;

        const method = String(submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'get') return 'جاري الاستعلام...';

        const text = getControlText(submitter);
        if (/delete|remove|cancel|destroy|حذف|إلغاء|الغاء|استرداد|رفض/i.test(text)) {
            return 'جاري التنفيذ...';
        }

        return 'جاري الحفظ...';
    }

    function preserveSubmitterValue(form, submitter, state) {
        if (! submitter || ! submitter.name || submitter.disabled) return;
        if (String(submitter.type || '').toLowerCase() === 'image') return;

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = submitter.name;
        hidden.value = submitter.value || '';
        hidden.dataset.submitLockValue = '1';
        form.appendChild(hidden);
        state.hiddenSubmitterValue = hidden;
    }

    function rememberButtonState(button, state) {
        state.buttons.push({
            element: button,
            disabled: button.disabled,
            ariaDisabled: button.getAttribute('aria-disabled'),
            html: button.tagName === 'BUTTON' ? button.innerHTML : null,
            value: button.tagName === 'INPUT' ? button.value : null,
            minWidth: button.style.minWidth,
        });
    }

    function renderBusyButton(button, label) {
        const width = button.getBoundingClientRect().width;
        if (width > 0) button.style.minWidth = `${Math.ceil(width)}px`;

        if (button.tagName === 'INPUT') {
            button.value = label;
            return;
        }

        button.innerHTML = '';

        const spinner = document.createElement('span');
        spinner.className = 'submit-lock-spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.className = 'submit-lock-label';
        text.textContent = label;

        button.append(spinner, text);
    }

    function lockForm(form, submitter) {
        if (! form || lockedForms.has(form)) return false;

        injectStyles();

        const buttons = collectSubmitControls(form);
        const visualButton = submitter && buttons.includes(submitter)
            ? submitter
            : buttons.find((button) => ! button.disabled) || null;
        const state = { buttons: [], hiddenSubmitterValue: null };

        preserveSubmitterValue(form, submitter, state);

        buttons.forEach((button) => {
            rememberButtonState(button, state);
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
            button.classList.add('is-submit-locked');
        });

        if (visualButton) {
            renderBusyButton(visualButton, getBusyText(form, visualButton));
        }

        form.dataset.submitLocked = '1';
        form.setAttribute('aria-busy', 'true');
        formStates.set(form, state);
        lockedForms.add(form);

        return true;
    }

    function unlockForm(form) {
        const state = formStates.get(form);
        if (! state) return;

        state.buttons.forEach((buttonState) => {
            const button = buttonState.element;
            button.disabled = buttonState.disabled;
            button.style.minWidth = buttonState.minWidth;
            button.classList.remove('is-submit-locked');

            if (buttonState.ariaDisabled === null) {
                button.removeAttribute('aria-disabled');
            } else {
                button.setAttribute('aria-disabled', buttonState.ariaDisabled);
            }

            if (buttonState.html !== null) button.innerHTML = buttonState.html;
            if (buttonState.value !== null) button.value = buttonState.value;
        });

        if (state.hiddenSubmitterValue) state.hiddenSubmitterValue.remove();

        delete form.dataset.submitLocked;
        form.removeAttribute('aria-busy');
        formStates.delete(form);
        lockedForms.delete(form);
    }

    function unlockAllForms() {
        Array.from(lockedForms).forEach(unlockForm);
    }

    function getEventSubmitter(event, form) {
        if (event.submitter && event.submitter.form === form) return event.submitter;
        if (lastSubmitter && lastSubmitter.form === form && Date.now() - lastSubmitterAt < 5000) return lastSubmitter;
        return null;
    }

    document.addEventListener('click', function (event) {
        const control = event.target.closest('button,input');
        if (! isSubmitControl(control) || ! control.form) return;
        lastSubmitter = control;
        lastSubmitterAt = Date.now();
    }, true);

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (! form || form.tagName !== 'FORM') return;

        const submitter = getEventSubmitter(event, form);
        if (isOptedOut(form, submitter)) return;
        lastSubmitter = null;
        lastSubmitterAt = 0;

        if (lockedForms.has(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        if (event.defaultPrevented) return;
        if (typeof form.checkValidity === 'function' && ! form.checkValidity()) return;

        lockForm(form, submitter);

        window.setTimeout(function () {
            if (event.defaultPrevented) {
                unlockForm(form);
                return;
            }

            const target = String(form.getAttribute('target') || '').toLowerCase();
            if (target && target !== '_self') {
                window.setTimeout(() => unlockForm(form), 1200);
            }
        }, 0);
    }, false);

    window.addEventListener('pageshow', unlockAllForms);

    window.RelaxSubmitLock = {
        lock: lockForm,
        unlock: unlockForm,
        unlockAll: unlockAllForms,
    };
})();
