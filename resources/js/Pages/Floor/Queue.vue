<script setup>
import { number } from '@/plugins/formatting';
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { clearFloorCache } from '@/floor/serviceWorker';
import NotSent from '@/Components/Floor/NotSent.vue';
import { guide, label, unitLabel } from '@/floor/dictionary';


const props = defineProps({
    machineCode: { type: String, default: null },
    // Minted at sign-in and handed down rather than fetched: the offline queue posts to the
    // device API with it, and seeding from the server means a kiosk whose storage was cleared
    // recovers on the next page load instead of bouncing back to the badge screen.
    deviceToken: { type: String, default: null },
    operator: { type: String, default: null },
    // Whether this person may take a record off the "Not sent" list — a supervisor, not an operator.
    canClearUnsent: { type: Boolean, default: false },
});

const operations = ref([]);
const loading = ref(true);
const error = ref(null);
/** When the queue on screen was last fetched from the server, if it did not come from one. */
const cachedAt = ref(null);
const { pending, rejected, online, flush, revision } = useOfflineQueue();

/** The "Not sent" list, shown in place of the queue. */
const showNotSent = ref(false);

/** How far along a step is, for the bar on its card. Never past the end of the bar. */
function progress(op) {
    const planned = Number(op.planned_qty);

    return planned > 0 ? Math.min(100, Math.round((Number(op.good_qty) / planned) * 100)) : 0;
}

/** dd/mm — the year is this one, and the card has no room for it. */
function dueDate(iso) {
    const [, month, day] = String(iso ?? '').split('-');

    return day ? `${day}/${month}` : null;
}

function overdue(iso) {
    return Boolean(iso) && iso < new Date().toISOString().slice(0, 10);
}

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
        error.value = 'no_saved_queue';
        loading.value = false;

        return;
    }

    if (response.status === 401) {
        localStorage.removeItem('octa.device_session');
        router.visit('/floor');

        return;
    }

    if (!response.ok) {
        error.value = 'queue_failed';
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

const sendingNow = ref(false);

async function sendNow() {
    if (sendingNow.value) return;

    sendingNow.value = true;
    await flush();
    sendingNow.value = false;
}

const ending = ref(false);
/** Why the shift could not be ended, when it could not: `{ key, params }` for the dictionary. */
const endBlocked = ref(null);

/**
 * End of shift. Without this the next operator at a shared kiosk inherits the previous one's
 * session and books their output under someone else's name.
 *
 * Nothing local is cleared until the server has confirmed the sign-out, and the shift cannot
 * be ended at all while records are waiting to be sent: signing out revokes the token those
 * records are posted with, so ending a shift during an outage used to discard them.
 */
async function endShift() {
    if (ending.value) return;

    ending.value = true;
    endBlocked.value = null;

    // One more attempt before deciding — the link may have come back a moment ago.
    await flush();

    if (pending.value > 0) {
        endBlocked.value = { key: 'end_blocked_pending', params: { n: pending.value } };
        ending.value = false;

        return;
    }

    if (!navigator.onLine) {
        endBlocked.value = { key: 'end_blocked_offline', params: {} };
        ending.value = false;

        return;
    }

    router.post('/floor/session/end', {}, {
        onSuccess: () => {
            localStorage.removeItem('octa.device_session');
            // The cached pages and work queue are this operator's too. Left behind, they would
            // be handed to the next badge at this kiosk the moment the link dropped.
            clearFloorCache();
        },
        onFinish: () => {
            ending.value = false;
        },
    });
}

onMounted(load);
</script>

<template>
    <FloorLayout>
        <Head title="Work queue" />

        <template #title>{{ label('work_queue') }}</template>
        <template #subtitle>{{ operator }}<span v-if="machineCode"> · {{ machineCode }}</span></template>

        <template #actions>
            <div class="flex flex-wrap items-center gap-3">
                <!-- A loom does not stop when the wifi does: queued writes replay when the link returns -->
                <span
                    class="rounded-full px-4 py-2 text-lg font-bold"
                    :class="online ? 'bg-emerald-600' : 'bg-amber-500 text-slate-900'"
                >
                    {{ label(online ? 'online' : 'offline') }}
                </span>

                <!-- Shown whenever anything is waiting, whatever the browser thinks of the link. -->
                <button
                    v-if="pending > 0"
                    class="min-h-11 rounded-full bg-amber-500 px-5 py-2 text-lg font-bold text-slate-900 disabled:opacity-60"
                    :disabled="sendingNow"
                    @click="sendNow"
                >
                    {{ label('waiting_count', { n: pending }) }} — {{ sendingNow ? '…' : label('send_now') }}
                </button>

                <button
                    class="min-h-11 rounded-full bg-white/10 px-5 py-2 text-lg font-bold hover:bg-white/20 disabled:opacity-60"
                    :disabled="ending"
                    @click="endShift"
                >
                    {{ ending ? '…' : label('end_shift') }}
                </button>
            </div>
        </template>

        <p v-if="error" role="alert" class="mb-4 rounded-xl bg-rose-600 px-5 py-4 text-xl font-semibold">{{ guide(error) }}</p>

        <!--
            A count that opens. It used to be a sentence with nothing behind it, and the
            supervisor who was called could not find out which records it meant.
        -->
        <button
            v-if="rejected && !showNotSent"
            class="mb-4 flex min-h-14 w-full flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-500 px-5 py-4 text-left text-lg font-semibold text-slate-900"
            data-open-not-sent
            @click="showNotSent = true"
        >
            <span>{{ label('not_sent_count', { n: rejected }) }}</span>
            <span class="rounded-full bg-slate-900 px-4 py-1 text-white">{{ label('view') }}</span>
        </button>

        <p v-if="endBlocked" role="alert" class="mb-4 rounded-xl bg-amber-500 px-5 py-4 text-xl font-semibold text-slate-900">
            {{ guide(endBlocked.key, endBlocked.params) }}
        </p>

        <!--
            Answered from the device's own cache because the link was down. Said plainly: the
            work below is real, it is simply as of a time that is not now, and a job card
            cancelled or reassigned since would still be sitting in this list.
        -->
        <p
            v-if="cachedAt"
            class="mb-4 rounded-xl bg-amber-500 px-5 py-4 text-lg font-semibold text-slate-900"
        >
            {{ guide('saved_list', { time: cachedTime }) }}
        </p>

        <NotSent v-if="showNotSent" :can-remove="canClearUnsent" :revision="revision" @close="showNotSent = false" />

        <p v-else-if="loading" class="text-2xl text-slate-400">…</p>

        <div v-else-if="operations.length === 0" class="rounded-2xl bg-white/5 px-6 py-10 text-center">
            <p class="text-2xl text-slate-300">{{ label('nothing_to_run') }}</p>
            <!--
                An empty queue has three ordinary causes and no way to tell them apart from the
                machine. Naming them stops the operator concluding the terminal is broken.
            -->
            <p class="mx-auto mt-3 max-w-lg text-lg leading-relaxed text-slate-300">
                {{ guide('queue_empty_why', { machine: machineCode ?? label('any_machine') }) }}
            </p>
        </div>

        <ul v-else class="space-y-3">
            <!--
                A real button inside each card: the whole card used to be a click handler on a
                list item, which a keyboard or a screen reader could not reach at all.
            -->
            <li v-for="op in operations" :key="op.operation_id">
                <button
                    class="block w-full rounded-2xl bg-white/5 p-5 text-left transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white"
                    :class="op.status === 'in_progress' ? 'ring-2 ring-emerald-400' : ''"
                    data-queue-card
                    @click="router.visit(`/floor/operations/${op.operation_id}`)"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-3 text-3xl font-bold">
                                {{ op.job_card.number }}
                                <!-- Which of these is already on a machine: the first thing a relieving operator asks. -->
                                <span
                                    class="rounded-full px-3 py-1 text-base font-bold"
                                    :class="op.status === 'in_progress' ? 'bg-emerald-500 text-white' : 'bg-white/10 text-slate-200'"
                                >
                                    {{ label(op.status === 'in_progress' ? 'running' : 'not_started') }}
                                </span>
                            </p>
                            <p class="text-xl text-slate-300">
                                {{ op.job_card.product_code }} · {{ op.name }}
                                <span v-if="op.job_card.colourway"> · {{ op.job_card.colourway }}</span>
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-lg text-slate-300">{{ op.machine ?? '—' }}</p>
                            <p
                                v-if="dueDate(op.job_card.due_date)"
                                class="text-lg font-semibold"
                                :class="overdue(op.job_card.due_date) ? 'text-rose-300' : 'text-slate-200'"
                            >
                                {{ label('due') }}: <span class="tnum">{{ dueDate(op.job_card.due_date) }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Made so far against the plan, with the unit: a bare "5,000" said neither. -->
                    <div class="mt-4">
                        <p class="flex flex-wrap items-baseline justify-between gap-x-4 text-xl">
                            <span>
                                <span class="font-bold tnum">{{ number(op.good_qty) }}</span>
                                <span class="text-slate-300"> / {{ number(op.planned_qty) }} {{ unitLabel(op.unit) }}</span>
                            </span>
                            <span class="font-bold tnum">{{ progress(op) }}%</span>
                        </p>
                        <div class="mt-2 h-3 overflow-hidden rounded-full bg-white/10" role="presentation">
                            <div class="h-full rounded-full bg-emerald-400" :style="{ width: `${progress(op)}%` }" />
                        </div>
                    </div>
                </button>
            </li>
        </ul>
    </FloorLayout>
</template>
