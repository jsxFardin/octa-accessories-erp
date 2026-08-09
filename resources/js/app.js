import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { ZiggyVue } from 'ziggy';

import permissions from '@/plugins/permissions';
import formatting, { configureFormatting } from '@/plugins/formatting';

const fallbackName = import.meta.env.VITE_APP_NAME || 'Octa ERP';

/** The tab title follows the organisation profile, so a rebrand needs no deploy. */
let appName = fallbackName;

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
        const organisation = props.initialPage.props.app ?? {};

        appName = organisation.short_name || fallbackName;
        configureFormatting(organisation);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .use(ZiggyVue)
            .use(permissions)
            .use(formatting)
            .mount(el);
    },
    progress: {
        // The brand azure, not Tailwind's default indigo — the bar is the first thing that
        // moves on every navigation and it should be the product's colour.
        color: '#0071be',
        showSpinner: false,
        delay: 120,
    },
});
