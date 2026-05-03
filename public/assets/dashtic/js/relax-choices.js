(function () {
    'use strict';

    const selector = 'select[data-relax-choice]';
    const instances = new WeakMap();

    function isValidRoot(root) {
        return root && (
            root.nodeType === Node.ELEMENT_NODE ||
            root.nodeType === Node.DOCUMENT_NODE ||
            root.nodeType === Node.DOCUMENT_FRAGMENT_NODE
        );
    }

    function optionText(select) {
        const emptyOption = select.querySelector('option[value=""]');
        return emptyOption ? emptyOption.textContent.trim() : '';
    }

    function initSelect(select) {
        if (!select || instances.has(select) || select.dataset.relaxChoiceReady === '1') {
            return;
        }

        if (!window.Choices) {
            return;
        }

        try {
            const instance = new Choices(select, {
                allowHTML: false,
                itemSelectText: '',
                loadingText: select.dataset.choiceLoading || 'جاري التحميل...',
                noChoicesText: select.dataset.choiceNoChoices || 'لا توجد خيارات',
                noResultsText: select.dataset.choiceNoResults || 'لا توجد نتائج',
                placeholder: true,
                placeholderValue: select.dataset.choicePlaceholder || optionText(select),
                position: select.dataset.choicePosition || 'auto',
                removeItemButton: select.multiple,
                searchEnabled: select.dataset.choiceSearch !== 'false',
                searchPlaceholderValue: select.dataset.choiceSearchPlaceholder || 'ابحث...',
                shouldSort: false,
            });

            instances.set(select, instance);
            select.dataset.relaxChoiceReady = '1';
        } catch (error) {
            console.warn('Relax Choices initialization failed', error);
        }
    }

    function refresh(root = document) {
        if (!window.Choices || !isValidRoot(root)) {
            return;
        }

        if (root.nodeType === Node.ELEMENT_NODE && root.matches(selector)) {
            initSelect(root);
        }

        root.querySelectorAll(selector).forEach(initSelect);
    }

    function destroy(root = document) {
        if (!isValidRoot(root)) {
            return;
        }

        const selects = [];
        if (root.nodeType === Node.ELEMENT_NODE && root.matches(selector)) {
            selects.push(root);
        }
        root.querySelectorAll(selector).forEach((select) => selects.push(select));

        selects.forEach((select) => {
            const instance = instances.get(select);
            if (!instance) {
                return;
            }

            instance.destroy();
            instances.delete(select);
            delete select.dataset.relaxChoiceReady;
        });
    }

    function observeDynamicRows() {
        if (!document.body || !window.MutationObserver) {
            return;
        }

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        refresh(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.relaxChoices = { refresh, destroy };

    document.addEventListener('DOMContentLoaded', () => {
        refresh(document);
        observeDynamicRows();
    });

    document.addEventListener('shown.bs.modal', (event) => refresh(event.target));
    document.addEventListener('livewire:navigated', () => refresh(document));
})();
