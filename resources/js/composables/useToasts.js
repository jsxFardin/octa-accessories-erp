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
 * How long each tone stays.
 *
 * Errors used to stay until dismissed, on the reasoning that a refusal names the rule that
 * stopped you and must not slide away while you are reading it. In practice they simply piled
 * up: a J3 refusal sat over the screen through the correction, the retry and the next page.
 * They time out now, but at twice the length — a refusal is a sentence to read, not a word —
 * and hovering one holds it open for as long as it is under the pointer.
 */
const DURATIONS = { error: 10000, warning: 7000, success: 5000 };

function schedule(id, tone) {
    clearTimeout(timers.get(id));
    timers.set(id, setTimeout(() => removeToast(id), DURATIONS[tone] ?? DURATIONS.success));
}

export function pushToast(tone, message) {
    if (!message) return null;

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
