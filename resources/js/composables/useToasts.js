import { ref } from 'vue';

/**
 * One toast stack for the whole application.
 *
 * It lives outside the component so anything can push into it — the flash watcher in
 * `Toasts.vue`, and the global Inertia error handler in `app.js`. Sixteen pages run their
 * forms inline and never render `form.errors`; on those, a 422 used to change nothing on
 * screen at all, which is the desk-side version of the shop floor's green "Logged" on a
 * rejected write.
 */
let sequence = 0;

const timers = new Map();

export const toasts = ref([]);

/**
 * How long each tone stays. An error has no duration: it stays until it is dismissed or
 * replaced.
 *
 * Errors have been both ways. Left up forever they piled over the screen through the
 * correction, the retry and the next page; given ten seconds they vanished while a slow reader
 * or a touch user (who has no hover to hold one open) was still on the first line — and on a
 * form with no inline errors the toast is the only explanation there is. So an error persists,
 * and the pile is prevented instead: there is one error at a time, a new one replaces the old,
 * and the next submit clears it (`clearErrorToasts`, called from `app.js`).
 */
const DURATIONS = { warning: 7000, success: 5000 };

function schedule(id, tone) {
    clearTimeout(timers.get(id));

    if (tone === 'error') return;

    timers.set(id, setTimeout(() => removeToast(id), DURATIONS[tone] ?? DURATIONS.success));
}

/** Drops every error on screen — a fresh attempt deserves a clean slate. */
export function clearErrorToasts() {
    toasts.value.filter((toast) => toast.tone === 'error').forEach((toast) => removeToast(toast.id));
}

export function pushToast(tone, message) {
    if (!message) return null;

    if (tone === 'error') clearErrorToasts();

    const id = ++sequence;
    toasts.value.push({ id, tone, message });
    schedule(id, tone);

    return id;
}

/** Reading a long refusal should not race a timer. */
export function holdToast(id) {
    clearTimeout(timers.get(id));
}

export function releaseToast(id, tone) {
    schedule(id, tone);
}

export function removeToast(id) {
    clearTimeout(timers.get(id));
    timers.delete(id);
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}
