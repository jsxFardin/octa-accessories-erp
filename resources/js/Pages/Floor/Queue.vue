<script setup>
import { onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import FloorLayout from '@/Layouts/FloorLayout.vue';
import { useOfflineQueue } from '@/Composables/useOfflineQueue';


const props = defineProps({ machineCode: { type: String, default: null } });

const operations = ref([]);
const operator = ref('');
const loading = ref(true);
const { pending, online } = useOfflineQueue();

async function load() {
    const session = JSON.parse(localStorage.getItem('octa.device_session') ?? 'null');

    if (!session) {
        router.visit('/floor');

        return;
    }

    operator.value = session.employee_name;

    const response = await fetch(`/api/v1/floor/queue?machine_code=${props.machineCode ?? ''}`, {
        headers: { Authorization: `Bearer ${session.token}`, Accept: 'application/json' },
    });

    if (response.status === 401) {
        localStorage.removeItem('octa.device_session');
        router.visit('/floor');

        return;
    }

    const payload = await response.json();
    operations.value = payload.operations;
    loading.value = false;
}

onMounted(load);
</script>

<template>
    <FloorLayout>
        <Head title="Floor queue" />

        <template #title>কাজের তালিকা · Work queue</template>
        <template #subtitle>{{ operator }}<span v-if="machineCode"> · {{ machineCode }}</span></template>

        <template #actions>
            <!-- A loom does not stop when the wifi does: queued writes replay when the link returns -->
            <span
                class="rounded-full px-4 py-2 text-lg font-bold"
                :class="online ? 'bg-emerald-600' : 'bg-amber-500 text-slate-900'"
            >
                {{ online ? 'ONLINE' : `OFFLINE · ${pending} queued` }}
            </span>
        </template>

        <p v-if="loading" class="text-2xl text-slate-400">…</p>

        <p v-else-if="operations.length === 0" class="rounded-2xl bg-white/5 px-6 py-10 text-center text-2xl text-slate-400">
            কোনো কাজ নেই · Nothing to run
        </p>

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
