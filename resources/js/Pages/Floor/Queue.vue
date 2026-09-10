<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';
import { useOfflineQueue } from '@/Composables/useOfflineQueue';
import { clearFloorCache } from '@/floor/serviceWorker';


const props = defineProps({
    machineCode: { type: String, default: null },
    // Minted at sign-in and handed down rather than fetched: the offline queue posts to the
    // device API with it, and seeding from the server means a kiosk whose storage was cleared
    // recovers on the next page load instead of bouncing back to the badge screen.
    deviceToken: { type: String, default: null },
    operator: { type: String, default: null },
});

const operations = ref([]);
const loading = ref(true);
const error = ref(null);
/** When the queue on screen was last fetched from the server, if it did not come from one. */
const cachedAt = ref(null);
const { pending, online } = useOfflineQueue();

const cachedTime = computed(() => (cachedAt.value
    ? new Date(cachedAt.value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : null));

async function load() {
    if (props.deviceToken) {
        localStorage.setItem('octa.device_session', JSON.stringify({
            token: props.deviceToken,
            employee_name: props.operator,
            machine_code: props.machineCode,
        }));
    }

    const session = JSON.parse(localStorage.getItem('octa.device_session') ?? 'null');

    if (!session) {
        router.visit('/floor');

        return;
    }

    let response;

    try {
        response = await fetch(`/api/v1/floor/queue?machine_code=${props.machineCode ?? ''}`, {
            headers: { Authorization: `Bearer ${session.token}`, Accept: 'application/json' },
        });
    } catch {
        // No link, and the service worker had nothing cached for this machine either — which
        // on a kiosk means this terminal has never been opened here with a connection. It
        // used to leave `loading` true, so the screen sat on an ellipsis for the rest of the
        // shift with no way to tell that from a slow queue.
        error.value = 'সংযোগ নেই · No connection, and no saved queue on this device. Reconnect, or ask your supervisor for the job card.';
        loading.value = false;

        return;
    }

    if (response.status === 401) {
        localStorage.removeItem('octa.device_session');
        router.visit('/floor');

        return;
    }

    if (!response.ok) {
        error.value = 'কাজের তালিকা আসেনি · The work queue could not be loaded. Call your supervisor.';
        loading.value = false;

        return;
    }

    const payload = await response.json();
    operations.value = payload.operations;
    // Set by the service worker when it answered from its cache instead of the server. A list
    // of job cards with no date on it looks exactly like a live one.
    cachedAt.value = payload.cached_at ?? null;
    loading.value = false;
}

/**
 * End of shift. Without this the next operator at a shared kiosk inherits the previous one's
 * session and books their output under someone else's name.
 */
function endShift() {
    localStorage.removeItem('octa.device_session');
    // The cached pages and work queue are this operator's too. Left behind, they would be
    // handed to the next badge at this kiosk the moment the link dropped.
    clearFloorCache();
    router.post('/floor/session/end');
}

onMounted(load);
</script>

<template>
    <FloorLayout>
        <Head title="Work queue" />

        <template #title>কাজের তালিকা · Work queue</template>
        <template #subtitle>{{ operator }}<span v-if="machineCode"> · {{ machineCode }}</span></template>

        <template #actions>
            <div class="flex items-center gap-3">
                <!-- A loom does not stop when the wifi does: queued writes replay when the link returns -->
                <span
                    class="rounded-full px-4 py-2 text-lg font-bold"
                    :class="online ? 'bg-emerald-600' : 'bg-amber-500 text-slate-900'"
                >
                    {{ online ? 'ONLINE' : `OFFLINE · ${pending} queued` }}
                </span>

                <button
                    class="rounded-full bg-white/10 px-5 py-2 text-lg font-bold hover:bg-white/20"
                    @click="endShift"
                >
                    শিফট শেষ · END SHIFT
                </button>
            </div>
        </template>

        <p v-if="error" class="mb-4 rounded-xl bg-rose-600 px-5 py-4 text-xl font-semibold">{{ error }}</p>

        <!--
            Answered from the device's own cache because the link was down. Said plainly: the
            work below is real, it is simply as of a time that is not now, and a job card
            cancelled or reassigned since would still be sitting in this list.
        -->
        <p
            v-if="cachedAt"
            class="mb-4 rounded-xl bg-amber-500 px-5 py-4 text-lg font-semibold text-slate-900"
        >
            সংরক্ষিত তালিকা · Saved list from {{ cachedTime }} — not live. New or cancelled work will not
            show until the connection returns.
        </p>

        <p v-if="loading" class="text-2xl text-slate-400">…</p>

        <div v-else-if="operations.length === 0" class="rounded-2xl bg-white/5 px-6 py-10 text-center">
            <p class="text-2xl text-slate-300">কোনো কাজ নেই · Nothing to run</p>
            <!--
                An empty queue has three ordinary causes and no way to tell them apart from the
                machine. Naming them stops the operator concluding the terminal is broken.
            -->
            <p class="mx-auto mt-3 max-w-lg text-lg leading-relaxed text-slate-400">
                Either nothing is scheduled for
                <span class="font-semibold">{{ machineCode ?? 'your unit' }}</span> right now, the job cards for it
                are not released yet, or the step before this one has not finished. Ask your supervisor to check the
                planning board.
            </p>
        </div>

        <ul v-else class="space-y-3">
            <li
                v-for="op in operations"
                :key="op.operation_id"
                class="rounded-2xl bg-white/5 p-5 transition hover:bg-white/10"
                @click="router.visit(`/floor/operations/${op.operation_id}`)"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-3xl font-bold">{{ op.job_card.number }}</p>
                        <p class="text-xl text-slate-300">
                            {{ op.job_card.product_code }} · {{ op.name }}
                            <span v-if="op.job_card.colourway"> · {{ op.job_card.colourway }}</span>
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-2xl font-bold tnum">{{ Number(op.planned_qty).toLocaleString() }}</p>
                        <p class="text-lg text-slate-400">{{ op.machine ?? '—' }}</p>
                    </div>
                </div>
            </li>
        </ul>
    </FloorLayout>
</template>
