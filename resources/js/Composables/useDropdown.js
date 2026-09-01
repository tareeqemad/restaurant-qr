import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Minimal dropdown state — open/close + outside-click + Escape.
 * Replaces Bootstrap's dropdown JS (which the Inertia shell doesn't load).
 * Bind `root` to the dropdown's wrapper element.
 */
export function useDropdown() {
    const open = ref(false);
    const root = ref(null);

    const toggle = () => { open.value = ! open.value; };
    const close = () => { open.value = false; };

    const onDocClick = (e) => {
        if (open.value && root.value && ! root.value.contains(e.target)) close();
    };
    const onKey = (e) => {
        if (e.key === 'Escape') close();
    };

    onMounted(() => {
        document.addEventListener('click', onDocClick, true);
        document.addEventListener('keydown', onKey);
    });
    onBeforeUnmount(() => {
        document.removeEventListener('click', onDocClick, true);
        document.removeEventListener('keydown', onKey);
    });

    return { open, root, toggle, close };
}
