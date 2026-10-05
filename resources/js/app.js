import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { ZiggyVue } from 'ziggy';

import { clearErrorToasts, pushToast } from '@/composables/useToasts';
import permissions from '@/plugins/permissions';
import formatting, { configureFormatting } from '@/plugins/formatting';
import { installRefusalGuard } from '@/plugins/refusals';

const fallbackName = import.meta.env.VITE_APP_NAME || 'Octa ERP';

/** The tab title follows the organisation profile, so a rebrand needs no deploy. */
let appName = fallbackName;

/*
 * A validation failure has to be visible on every page, not only the ones that thought to
 * render `form.errors`. Pages that run a form inline — a challan's remarks box, the packing
 * list's carton rows — showed nothing at all when the server answered 422, so the operator
 * pressed the button again. The inline field errors still render where a page renders them;
 * this is the floor under them.
 */
router.on('error', (event) => {
    const messages = [...new Set(Object.values(event.detail?.errors ?? {}).flat().filter(Boolean))];

    if (messages.length === 0) return;

    // Every message, not "the first and 3 more": on a form that renders no inline errors this
    // toast is the whole explanation, and a count tells nobody which fields to fix.
    pushToast('error', messages.length === 1
        ? messages[0]
        : `${messages.length} things to fix:\n${messages.map((message) => `• ${message}`).join('\n')}`);
});

// A new attempt starts clean. Errors stay on screen until dismissed, so without this the
// refusal from the last save would still be showing over the result of this one.
router.on('start', (event) => {
    if ((event.detail?.visit?.method ?? 'get').toLowerCase() !== 'get') clearErrorToasts();
});

// A refused write must not run a page's `onSuccess` — see `plugins/refusals.js`.
installRefusalGuard(router);

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
