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

export const toasts = ref([]);

/** Errors stay until dismissed; everything else clears itself. */
export function pushToast(tone, message) {
    if (!message) return null;

    const id = ++sequence;
    toasts.value.push({ id, tone, message });

    if (tone !== 'error') {
        setTimeout(() => removeToast(id), 5000);
    }

    return id;
}

export function removeToast(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}
