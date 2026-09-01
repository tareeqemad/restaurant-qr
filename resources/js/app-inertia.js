/**
 * The single JavaScript entry for every interactive staff and customer page.
 * Server-rendered print/error documents deliberately have no JS dependency.
 */
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import './date-picker';

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    progress: {
        color: '#176b45',
        delay: 120,
        showSpinner: false,
    },

    // The project is deployed on shared hosting and serves both staff screens
    // and public QR pages. Load only the requested page so a diner opening the
    // menu never downloads cashier, kitchen, inventory, and accounting code.
    resolve: (name) => {
        const load = pages[`./Pages/${name}.vue`];

        if (! load) throw new Error(`Unknown Inertia page: ${name}`);

        return load();
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el);
    },
});
