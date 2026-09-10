/*
 * The shop-floor service worker.
 *
 * The offline queue (`useOfflineQueue`) already keeps four hours of writes across an outage,
 * so the *data* survives a dead link. The *application* did not: a kiosk that reloaded — a
 * stray refresh, a tablet waking up, Android reaping a backgrounded tab — got the browser's
 * "site can't be reached" page and an operator with no terminal until the wifi returned, with
 * the shift's queued output still sitting in localStorage behind it.
 *
 * This worker closes that gap and nothing else. It never touches a write: only GET requests
 * are handled, so every start/log/finish/downtime still goes through the queue, with its
 * idempotency key and its `occurred_at` stamp, exactly as before.
 *
 * `PRECACHE` and `VERSION` are prepended by `ServiceWorkerController` from the Vite manifest —
 * this file is not served as-is.
 */

/* global PRECACHE, VERSION */

/**
 * Two caches, because they have opposite lifetimes.
 *
 * Build output is immutable and named by content hash, so it is keyed by the deploy and kept
 * across a shift. Pages and the work queue are somebody's session, and are thrown away when
 * that somebody ends their shift — a shared kiosk must not hand the next operator the last
 * one's job cards, offline or not.
 */
const ASSETS = `octa-floor-assets-${VERSION}`;
const PAGES = 'octa-floor-pages';

const QUEUE_API = '/api/v1/floor/queue';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(ASSETS).then((cache) => cache.addAll(PRECACHE)));

    // Take over immediately rather than waiting for every floor tab to close. A kiosk tab is
    // never closed, so the default would leave a deploy unapplied until someone rebooted the
    // tablet — which in practice means never.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        /*
         * The previous deploy's assets are kept, not deleted.
         *
         * A page that is already open has the old hashed chunk names baked into its module
         * graph, and Inertia loads screens lazily — so the operation screen is fetched at the
         * moment the operator taps a job card. After a deploy those names are gone from the
         * server. Holding one generation back means a terminal mid-shift keeps working until
         * it next reloads, instead of dying on the first tap after a release.
         */
        const keys = await caches.keys();
        const mine = keys.filter((key) => key.startsWith('octa-floor-assets-'));

        await Promise.all(
            mine.slice(0, Math.max(0, mine.length - 2))
                .filter((key) => key !== ASSETS)
                .map((key) => caches.delete(key)),
        );

        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    // End of shift. The build output stays — it is not anybody's data — but every page and
    // work queue this operator saw is dropped.
    if (event.data?.type === 'CLEAR_SESSION') {
        event.waitUntil(caches.delete(PAGES));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Writes are the offline queue's job, and replaying one from here would book a second
    // shift's output. Cross-origin is nobody's business but the browser's.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigation(request));

        return;
    }

    /*
     * An in-app tap rather than a reload: Inertia fetches the next screen as JSON instead of
     * navigating, so none of it goes through the branch above. Without this, a terminal that
     * reloaded offline came back with its queue on screen and every job card in it inert.
     *
     * Laravel sends `Vary: X-Inertia`, so the cache keeps a page's JSON and its HTML apart
     * even though they share a URL — which is the whole reason this can share a cache with
     * the navigations.
     */
    if (request.headers.get('X-Inertia') && url.pathname.startsWith('/floor')) {
        event.respondWith(inertia(request));

        return;
    }

    // Hashed and immutable: if it is in the cache it is the right file, by definition.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(asset(request));

        return;
    }

    if (url.pathname === QUEUE_API) {
        event.respondWith(workQueue(request));
    }
});

/**
 * The precached set and nothing else — this reads the cache, it never adds to it.
 *
 * Storing every `/build/` file that went past looked like free insurance and was the
 * opposite. Vite prefetches the whole application's lazily-loaded screens on every page load,
 * so one visit to the badge screen pulled roughly 150 chunks — the finance forms, the
 * procurement grids, the audit log — and the worker filed all of them on a factory tablet.
 * What the terminal needs offline is known at install time, and it is five screens.
 */
async function asset(request) {
    const cache = await caches.open(ASSETS);

    return (await cache.match(request)) ?? fetch(request);
}

/**
 * Where a page is filed.
 *
 * A screen has two representations at one URL — the HTML a reload gets, and the JSON Inertia
 * gets for a tap — and the server tells them apart with `Vary: X-Inertia`, correctly. Chrome's
 * Cache API does not honour that on `put`: storing the JSON deleted the HTML for the same URL,
 * so warming the queue for the QUEUE button cost us the reload that was the whole point.
 *
 * Rather than depend on `Vary` behaving, the JSON gets a URL of its own. Nothing requests that
 * URL — it is only ever a cache key.
 */
function pageKey(request) {
    if (!request.headers.get('X-Inertia')) {
        return request;
    }

    const url = new URL(request.url);
    url.searchParams.set('__inertia', '1');

    return new Request(url.toString(), { credentials: 'same-origin' });
}

/**
 * Network first, so an online terminal is never a stale one — the cache exists for the moment
 * there is no link at all, and for nothing else.
 *
 * @returns {Promise<Response|null>} null when the link is down and nothing was stored.
 */
async function fresh(request) {
    const cache = await caches.open(PAGES);
    const key = pageKey(request);

    try {
        const response = await fetch(request);

        if (response.ok) {
            cache.put(key, response.clone());
        }

        return response;
    } catch {
        return (await cache.match(key)) ?? null;
    }
}

/** A reload, or the home-screen icon. */
async function navigation(request) {
    return (await fresh(request))
        // Whatever they reloaded, the queue is the screen they can work from, and every job
        // card on it is one tap away.
        ?? (await caches.match('/floor/queue', { cacheName: PAGES }))
        ?? offlinePage();
}

/**
 * A tap. There is no sensible substitute for the screen the operator asked for, so a miss is
 * a failed visit — which `FloorLayout` turns into a sentence about the connection rather than
 * a tap that appears to do nothing.
 */
async function inertia(request) {
    const response = await fresh(request);

    if (response === null) {
        throw new Error('offline, and this screen is not saved on this device');
    }

    return response;
}

/**
 * The work list, so a kiosk that reloads with no link still shows the jobs it was showing.
 * Stamped, because a list of job cards with no date on it is indistinguishable from a live
 * one, and an operator should never have to guess which they are looking at.
 */
async function workQueue(request) {
    const cache = await caches.open(PAGES);

    try {
        const response = await fetch(request);

        if (response.ok) {
            const stamped = new Response(response.clone().body, response);
            stamped.headers.set('X-Cached-At', new Date().toISOString());
            cache.put(request, stamped);
        }

        return response;
    } catch {
        const hit = await cache.match(request);

        if (!hit) {
            throw new Error('offline, and this queue has not been loaded on this device');
        }

        // The page reads this to say so on screen rather than presenting stale work as current.
        const body = await hit.clone().json();
        body.cached_at = hit.headers.get('X-Cached-At');

        return new Response(JSON.stringify(body), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

/** Only reached on a device that has never loaded the terminal online. */
function offlinePage() {
    return new Response(
        `<!doctype html>
<html lang="bn"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offline</title>
<style>
  html,body{height:100%;margin:0}
  body{display:grid;place-items:center;background:#020617;color:#f8fafc;
       font-family:system-ui,sans-serif;text-align:center;padding:2rem}
  h1{font-size:2rem;margin:0 0 .75rem}
  p{font-size:1.25rem;color:#94a3b8;margin:0 0 2rem;line-height:1.6}
  button{background:#0071be;color:#fff;border:0;border-radius:1rem;
         padding:1.25rem 2.5rem;font-size:1.5rem;font-weight:700}
</style></head>
<body><div>
  <h1>সংযোগ নেই · No connection</h1>
  <p>এই ডিভাইসে টার্মিনাল এখনো লোড হয়নি।<br>
     The terminal has not been loaded on this device yet — connect once, then it works offline.</p>
  <button onclick="location.reload()">আবার চেষ্টা · RETRY</button>
</div></body></html>`,
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
}
