<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Modal from '@/Components/Ui/Modal.vue';

/**
 * What happens when a save arrives after the session has run out.
 *
 * The server answers such a request with 419. That used to replace the whole page with an error
 * screen, so someone back from lunch pressed Save on a twelve-line quotation and lost all of
 * it. The server now leaves a refused save alone (see `bootstrap/app.php`), the response
 * arrives here as an HTTP exception, and the page — with everything typed into it — stays
 * exactly where it was while the user signs in again in another tab.
 *
 * Signing in elsewhere renews the cookies this tab sends, so pressing Save again then works.
 * "I have signed in" checks that before closing: a save sent while still signed out would be
 * redirected to the sign-in page and lose the form after all.
 */
const open = ref(false);
const checking = ref(false);
const stillSignedOut = ref(false);

let stop = null;

onMounted(() => {
    stop = router.on('httpException', (event) => {
        if (event.detail.response?.status !== 419) return;

        // Handled here; without this Inertia shows the raw response in an overlay.
        event.preventDefault();
        stillSignedOut.value = false;
        open.value = true;
    });
});

onUnmounted(() => stop?.());

async function confirmSignedIn() {
    checking.value = true;
    stillSignedOut.value = false;

    try {
        const response = await fetch('/notifications', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            // A signed-out request is redirected to the sign-in page, and a followed redirect
            // comes back 200 — which would read as "signed in". Not followed, it is not `ok`.
            redirect: 'manual',
        });

        if (response.ok) {
            open.value = false;
        } else {
            stillSignedOut.value = true;
        }
    } catch {
        stillSignedOut.value = true;
    } finally {
        checking.value = false;
    }
}
</script>

<template>
    <Modal
        v-model:open="open"
        title="You have been signed out"
        subtitle="Nothing you typed on this page has been lost."
        width="max-w-md"
        :close-on-backdrop="false"
    >
        <ol class="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-ink-800">
            <li>
                <a href="/login" target="_blank" rel="noopener" class="font-medium text-brand-700 underline">Sign in again in a new tab</a>.
                Leave this tab open.
            </li>
            <li>Come back to this tab and choose <span class="font-medium">I have signed in</span>.</li>
            <li>Press Save again.</li>
        </ol>

        <p class="mt-3 text-xs leading-relaxed text-ink-600">
            The system signs you out after a period without activity, so that an unattended
            screen cannot be used by someone else.
        </p>

        <p v-if="stillSignedOut" role="alert" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
            This browser is still signed out. Sign in in the other tab first, then try again.
        </p>

        <template #footer>
            <Button variant="primary" :loading="checking" :disabled="checking" @click="confirmSignedIn">
                I have signed in
            </Button>
        </template>
    </Modal>
</template>
