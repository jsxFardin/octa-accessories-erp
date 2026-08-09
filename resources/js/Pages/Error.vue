<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';

defineOptions({ layout: null });

const props = defineProps({
    status: { type: Number, required: true },
    home: { type: String, default: '/' },
});

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

/**
 * A 403 should tell you what happened and where you can go — not leave you on a blank page
 * with no navigation and no way to sign out as someone else.
 */
const MESSAGES = {
    403: {
        title: 'Not your permission',
        body: 'Your role does not include this screen. Nothing is wrong with your account — this page simply is not part of what you do.',
    },
    404: {
        title: 'Nothing here',
        body: 'That page or record does not exist. It may have been cancelled, or the link may be out of date.',
    },
    419: {
        title: 'Session expired',
        body: 'You were away long enough for the session to lapse. Sign in again and carry on.',
    },
    429: {
        title: 'Too many attempts',
        body: 'Slow down for a minute, then try again.',
    },
    500: {
        title: 'Something broke',
        body: 'The error has been logged. Nothing you did caused it.',
    },
    503: {
        title: 'Down for maintenance',
        body: 'The system is being updated. It will be back shortly.',
    },
};

const message = computed(() => MESSAGES[props.status] ?? MESSAGES[500]);

function logout() {
    router.post('/logout');
}
</script>

<template>
    <Head :title="`${status} — ${message.title}`" />

    <div class="flex min-h-screen items-center justify-center bg-slate-100 p-4">
        <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center gap-3">
                <span class="rounded-md bg-slate-900 px-2.5 py-1 font-mono text-sm font-bold text-white">
                    {{ status }}
                </span>
                <h1 class="text-lg font-semibold text-ink-900">{{ message.title }}</h1>
            </div>

            <p class="text-sm text-ink-700">{{ message.body }}</p>

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <Button v-if="status === 419 || !signedIn" variant="primary" href="/login">Sign in</Button>
                <Button v-else variant="primary" :href="home">Go to my home page</Button>

                <!-- The way out that a blank 403 never offered. -->
                <Button v-if="signedIn" @click="logout">Sign out</Button>
            </div>

            <p v-if="status === 403 && signedIn" class="mt-4 text-xs text-ink-500">
                Signed in as <span class="font-medium">{{ page.props.auth.user.name }}</span>
                ({{ page.props.auth.user.roles.map((r) => r.replace(/_/g, ' ')).join(', ') }}).
                If you need this screen, ask an administrator to review your role.
            </p>
        </div>
    </div>
</template>
