import flatpickr from 'flatpickr';
import { Arabic } from 'flatpickr/dist/l10n/ar.js';
import monthSelectPlugin from 'flatpickr/dist/plugins/monthSelect/index.js';

import 'flatpickr/dist/flatpickr.css';
import 'flatpickr/dist/plugins/monthSelect/style.css';
import '../css/date-picker.css';

const DATE_INPUT_SELECTOR = [
    'input[type="date"]',
    'input[type="datetime-local"]',
    'input[type="month"]',
].join(',');

const enhancedInputs = new Set();
const sourceByVisibleInput = new WeakMap();
let pickerSequence = 0;

const pickerDefinition = (kind) => {
    if (kind === 'datetime-local') {
        return {
            valueFormat: 'Y-m-d\\TH:i',
            altFormat: 'j F Y - H:i',
            placeholder: 'اختر التاريخ والوقت',
            currentLabel: 'الآن',
            enableTime: true,
        };
    }

    if (kind === 'month') {
        return {
            valueFormat: 'Y-m',
            altFormat: 'F Y',
            placeholder: 'اختر الشهر',
            currentLabel: 'هذا الشهر',
            enableTime: false,
        };
    }

    return {
        valueFormat: 'Y-m-d',
        altFormat: 'j F Y',
        placeholder: 'اختر التاريخ',
        currentLabel: 'اليوم',
        enableTime: false,
    };
};

const sourceKind = (input) => input.dataset.rqDateKind || input.getAttribute('type') || 'date';

const sourceLabel = (input, definition) => {
    const explicitLabel = input.getAttribute('aria-label');
    if (explicitLabel) return explicitLabel;

    const labelledBy = input.getAttribute('aria-labelledby');
    if (labelledBy) {
        const label = document.getElementById(labelledBy);
        if (label?.textContent?.trim()) return label.textContent.trim();
    }

    if (input.id) {
        const escapedId = window.CSS?.escape ? window.CSS.escape(input.id) : input.id;
        const label = document.querySelector(`label[for="${escapedId}"]`);
        if (label?.textContent?.trim()) return label.textContent.trim();
    }

    return input.getAttribute('placeholder') || definition.placeholder;
};

const currentSourceValue = (input, instance, definition) => {
    if (! instance.selectedDates.length) return '';

    return instance.formatDate(instance.selectedDates[0], definition.valueFormat);
};

const syncSourceValue = (input, instance, definition) => {
    const value = input.value || '';
    const selectedValue = currentSourceValue(input, instance, definition);

    if (value === selectedValue) return;

    if (! value) {
        instance.clear(false);
        return;
    }

    instance.setDate(value, false, definition.valueFormat);
};

const syncPickerState = (input, { syncValue = false } = {}) => {
    const instance = input._flatpickr;
    if (! instance) return;

    const definition = pickerDefinition(sourceKind(input));
    const minDate = input.getAttribute('min') || '';
    const maxDate = input.getAttribute('max') || '';
    const locked = input.disabled || input.readOnly;

    if (input.dataset.rqDateMin !== minDate) {
        input.dataset.rqDateMin = minDate;
        instance.set('minDate', minDate || null);
    }

    if (input.dataset.rqDateMax !== maxDate) {
        input.dataset.rqDateMax = maxDate;
        instance.set('maxDate', maxDate || null);
    }

    if (input.dataset.rqDateLocked !== String(locked)) {
        input.dataset.rqDateLocked = String(locked);
        instance.set('clickOpens', ! locked);
    }

    if (syncValue) syncSourceValue(input, instance, definition);

    const visibleInput = instance.altInput;
    if (! visibleInput) return;

    visibleInput.disabled = input.disabled;
    visibleInput.readOnly = true;
    visibleInput.required = input.required && ! input.disabled;
    visibleInput.setAttribute('aria-required', input.required ? 'true' : 'false');
    visibleInput.setAttribute('aria-readonly', input.readOnly ? 'true' : 'false');
    visibleInput.classList.toggle('is-readonly', input.readOnly);

    const describedBy = input.getAttribute('aria-describedby');
    if (describedBy) visibleInput.setAttribute('aria-describedby', describedBy);
    else visibleInput.removeAttribute('aria-describedby');

    const invalid = input.getAttribute('aria-invalid');
    if (invalid) visibleInput.setAttribute('aria-invalid', invalid);
    else visibleInput.removeAttribute('aria-invalid');

    const clearButton = instance.calendarContainer?.querySelector('[data-rq-date-clear]');
    if (clearButton) clearButton.hidden = input.required || input.readOnly;

    const currentButton = instance.calendarContainer?.querySelector('[data-rq-date-current]');
    if (currentButton) {
        currentButton.disabled = locked || ! instance.isEnabled(new Date(), sourceKind(input) !== 'datetime-local');
    }
};

const appendPickerFooter = (input, instance, definition) => {
    if (! instance.calendarContainer || instance.calendarContainer.querySelector('[data-rq-date-footer]')) return;

    const footer = document.createElement('div');
    footer.className = 'rq-date-picker__footer';
    footer.dataset.rqDateFooter = '';

    const currentButton = document.createElement('button');
    currentButton.type = 'button';
    currentButton.className = 'rq-date-picker__shortcut';
    currentButton.dataset.rqDateCurrent = '';
    currentButton.textContent = definition.currentLabel;
    currentButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        instance.setDate(new Date(), true, definition.valueFormat);
        instance.close();
    });

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'rq-date-picker__clear';
    clearButton.dataset.rqDateClear = '';
    clearButton.textContent = 'مسح';
    clearButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        instance.clear(true);
        instance.close();
    });

    footer.append(currentButton, clearButton);
    instance.calendarContainer.append(footer);
};

const decoratePicker = (input, instance, definition, kind) => {
    const pickerId = `rq-date-picker-${++pickerSequence}`;
    const visibleInput = instance.altInput;

    instance.calendarContainer.classList.add('rq-date-picker', `rq-date-picker--${kind}`);
    instance.calendarContainer.id = pickerId;
    instance.calendarContainer.setAttribute('dir', 'rtl');

    if (visibleInput) {
        visibleInput.classList.add('rq-date-input', `rq-date-input--${kind}`);
        visibleInput.placeholder = input.getAttribute('placeholder') || definition.placeholder;
        visibleInput.autocomplete = 'off';
        visibleInput.setAttribute('role', 'combobox');
        visibleInput.setAttribute('aria-haspopup', 'dialog');
        visibleInput.setAttribute('aria-controls', pickerId);
        visibleInput.setAttribute('aria-expanded', 'false');
        visibleInput.setAttribute('aria-label', sourceLabel(input, definition));
        sourceByVisibleInput.set(visibleInput, input);
    }

    appendPickerFooter(input, instance, definition);
    syncPickerState(input, { syncValue: true });
};

const enhanceDateInput = (input) => {
    if (! (input instanceof HTMLInputElement)) return;
    if (input.dataset.nativeDatepicker !== undefined || input._flatpickr) return;

    const kind = input.getAttribute('type');
    if (! ['date', 'datetime-local', 'month'].includes(kind)) return;

    const definition = pickerDefinition(kind);
    input.dataset.rqDateKind = kind;
    input.dataset.rqDateEnhanced = 'true';
    enhancedInputs.add(input);

    const options = {
        locale: {
            ...Arabic,
            firstDayOfWeek: 6,
            time_24hr: true,
        },
        altInput: true,
        altInputClass: 'rq-date-input',
        altFormat: definition.altFormat,
        dateFormat: definition.valueFormat,
        defaultDate: input.value || null,
        disableMobile: true,
        allowInput: false,
        clickOpens: ! input.disabled && ! input.readOnly,
        enableTime: definition.enableTime,
        time_24hr: true,
        minuteIncrement: 5,
        minDate: input.getAttribute('min') || null,
        maxDate: input.getAttribute('max') || null,
        ariaDateFormat: 'j F Y',
        onReady: [(_dates, _value, instance) => decoratePicker(input, instance, definition, kind)],
        onOpen: [(_dates, _value, instance) => {
            syncPickerState(input, { syncValue: true });
            instance.altInput?.setAttribute('aria-expanded', 'true');
        }],
        onClose: [(_dates, _value, instance) => instance.altInput?.setAttribute('aria-expanded', 'false')],
        onDestroy: [() => {
            enhancedInputs.delete(input);
            delete input.dataset.rqDateEnhanced;
            delete input.dataset.rqDateKind;
            delete input.dataset.rqDateMin;
            delete input.dataset.rqDateMax;
            delete input.dataset.rqDateLocked;
        }],
    };

    if (kind === 'month') {
        options.plugins = [monthSelectPlugin({
            shorthand: false,
            dateFormat: definition.valueFormat,
            altFormat: definition.altFormat,
        })];
    }

    flatpickr(input, options);
};

const scanForDateInputs = (root) => {
    if (! root) return;

    if (root instanceof HTMLInputElement && root.matches(DATE_INPUT_SELECTOR)) {
        enhanceDateInput(root);
    }

    root.querySelectorAll?.(DATE_INPUT_SELECTOR).forEach(enhanceDateInput);
};

const destroyDateInputsWithin = (root) => {
    if (! (root instanceof Element)) return;

    const candidates = [];
    if (root.matches?.('[data-rq-date-enhanced]')) candidates.push(root);
    root.querySelectorAll?.('[data-rq-date-enhanced]').forEach((input) => candidates.push(input));

    candidates.forEach((input) => input._flatpickr?.destroy());
};

const bootDatePickers = () => {
    scanForDateInputs(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(scanForDateInputs);
                mutation.removedNodes.forEach(destroyDateInputsWithin);
                return;
            }

            if (mutation.type === 'attributes' && mutation.target instanceof HTMLInputElement) {
                syncPickerState(mutation.target, { syncValue: mutation.attributeName === 'value' });
            }
        });
    });

    observer.observe(document.documentElement, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['min', 'max', 'disabled', 'readonly', 'required', 'value', 'aria-invalid', 'aria-describedby'],
    });

    document.addEventListener('pointerdown', (event) => {
        const source = sourceByVisibleInput.get(event.target);
        if (source) syncPickerState(source, { syncValue: true });
    }, true);

    document.addEventListener('reset', (event) => {
        window.setTimeout(() => scanForDateInputs(event.target), 0);
        window.setTimeout(() => {
            enhancedInputs.forEach((input) => {
                if (event.target.contains(input)) syncPickerState(input, { syncValue: true });
            });
        }, 0);
    }, true);

    document.addEventListener('inertia:finish', () => window.queueMicrotask(() => scanForDateInputs(document)));
    window.addEventListener('pageshow', () => enhancedInputs.forEach((input) => syncPickerState(input, { syncValue: true })));

    window.setInterval(() => {
        if (document.hidden) return;

        enhancedInputs.forEach((input) => {
            if (! input.isConnected) {
                input._flatpickr?.destroy();
                enhancedInputs.delete(input);
                return;
            }

            // Vue can update the value property without changing its HTML
            // attribute. This light sync keeps the visible Arabic field exact.
            syncPickerState(input, { syncValue: true });
        });
    }, 750);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootDatePickers, { once: true });
} else {
    bootDatePickers();
}
