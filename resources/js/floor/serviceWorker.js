/**
 * Registering (and un-registering) the shop-floor service worker.
 *
 * Scoped to `/floor` and registered only from floor screens: the desk is used at a desk, on a
 * machine with a network, and has no business holding a cache of somebody's job cards.
 */

const URL = '/floor/sw.js';
const SCOPE = '/floor';

let registration = null;

export function registerFloorServiceWorker() {
    // Requires a secure context — HTTPS, or localhost. On a plain-HTTP UAT box the terminal
    // still works exactly as it did; it simply does not survive a reload offline.
    if (!('serviceWorker' in navigator)) {
        return;
    }

    // Registration competes with the first paint for the same connection, and the operator is
    // waiting on the paint. It only matters from the *second* load onwards, so it can wait.
    const start = () => navigator.serviceWorker
        .register(URL, { scope: SCOPE })
        .then((result) => {
            registration = result;
        })
        .catch((reason) => {
            /*
             * An insecure origin (plain HTTP on anything but localhost), a kiosk with workers
             * disabled by policy, a scope the browser will not allow. None of them are worth a
             * message on a screen an operator cannot act on — the terminal still works, it
             * simply stops working the moment the link drops, which is exactly what it did
             * before this existed.
             *
             * Logged rather than swallowed: a silent catch here hid a SecurityError for the
             * whole of this feature's first build, and the symptom of a worker that never
             * registers is a terminal that looks completely fine until the wifi goes.
             */
            console.warn('[floor] offline support is off — the service worker did not register.', reason);
        });

    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start, { once: true });
    }
}

/**
 * Fetch the work queue the way a *tap* fetches it, so the worker has that copy too.
 *
 * A page reached by navigating is stored as HTML; the same page reached by tapping is Inertia's
 * JSON, and `Vary: X-Inertia` — rightly — keeps the two apart. A queue that had only ever been
 * reloaded was therefore not there for the QUEUE button on the operation screen, and the one
 * control an operator uses to get back to their work list was dead in exactly the outage this
 * was all built for.
 *
 * It has to happen here rather than in the worker because Inertia answers 409 to a request
 * carrying no `X-Inertia-Version`, and the version is something only the page knows.
 *
 * @param  {string} version  Inertia's asset version, from `usePage().version`.
 */
export function warmQueue(version) {
    if (!navigator.serviceWorker?.controller || !version) {
        return;
    }

    fetch('/floor/queue', {
        credentials: 'same-origin',
        headers: {
            'X-Inertia': 'true',
            'X-Inertia-Version': version,
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'text/html, application/xhtml+xml',
        },
    }).catch(() => {
        // Offline already, which is the one case where there is nothing to warm.
    });
}

/**
 * End of shift. localStorage is cleared by the caller for the same reason this is: the next
 * operator at a shared kiosk must not inherit the last one's work — and a cached queue would
 * hand it to them the moment the wifi dropped.
 *
 * Best-effort and deliberately not awaited. Ending a shift must not hang on a cache.
 */
export function clearFloorCache() {
    const worker = registration?.active ?? navigator.serviceWorker?.controller;

    worker?.postMessage({ type: 'CLEAR_SESSION' });
}
