import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { ZiggyVue } from 'ziggy';

import permissions from '@/plugins/permissions';
import formatting from '@/plugins/formatting';

const appName = import.meta.env.VITE_APP_NAME || 'Octa ERP';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Each page mounts its own layout (<AppLayout>, <FloorLayout>) rather than having one
    // assigned here. Vue only passes named slots — the page title, subtitle and action buttons —
    // to a component the page renders itself.
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob('./Pages/**/*.vue'),
    ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .use(ZiggyVue)
            .use(permissions)
            .use(formatting)
            .mount(el);
    },
    progress: {
        color: '#4f46e5',
        showSpinner: true,
    },
});
