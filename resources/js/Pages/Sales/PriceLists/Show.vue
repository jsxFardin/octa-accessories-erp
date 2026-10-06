<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, pcs, rate } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';

const props = defineProps({
    list: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    /** Other active lists for the same customer live on the same days. */
    overlapping: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();

/** Grouped by product, because the breaks only make sense read together. */
const byProduct = computed(() => {
    const groups = new Map();

    for (const line of props.lines) {
        const key = line.product_code ?? '—';

        if (!groups.has(key)) groups.set(key, { product: line, breaks: [] });

        groups.get(key).breaks.push(line);
    }

    return [...groups.values()];
});

const STANDINGS = {
    current: { label: 'Current', tone: 'success', text: 'Quotations raised today read these rates.' },
    ending: { label: 'Ending within 30 days', tone: 'warning', text: 'Still read by quotations today; agree the next list before it ends.' },
    upcoming: { label: 'Starts later', tone: 'info', text: 'Not read yet; quotations will use it from the start date.' },
    lapsed: { label: 'Lapsed', tone: 'danger', text: 'Past its end date. Quotations raised today are costed from the cost sheet alone.' },
    inactive: { label: 'Inactive', tone: 'neutral', text: 'Switched off. Quotations already priced from it keep their rates.' },
};

const standing = computed(() => STANDINGS[props.list.standing] ?? STANDINGS.current);

async function deactivate() {
    if (!await confirm({
        title: `Deactivate ${props.list.code}?`,
        message: 'New quotations will stop reading its rates. Quotations already priced from it keep theirs. It can be reactivated later.',
        confirmLabel: 'Deactivate',
    })) return;

    router.delete(`/price-lists/${props.list.id}`);
}
</script>

<template>
    <AppLayout>
        <Head :title="list.code" />

        <template #title>{{ list.code }}</template>
        <template #subtitle>
            {{ list.name }} ·
            <Link v-if="list.customer_id" :href="`/customers/${list.customer_id}`" class="doc-link">{{ list.customer }}</Link><template v-else>{{ list.customer }}</template>
            · {{ list.currency }}
        </template>

        <template #actions>
            <Badge :tone="standing.tone" :label="standing.label" />
            <Button
                v-if="!list.is_active && can('price_list.update')"
                size="sm"
                variant="primary"
                data-reactivate
                @click="router.post(`/price-lists/${list.id}/reactivate`, {}, { preserveScroll: true })"
            >Reactivate</Button>
            <Button v-if="can('price_list.update')" size="sm" :href="`/price-lists/${list.id}/edit`">Edit</Button>
            <Button v-if="list.is_active && can('price_list.delete')" size="sm" variant="danger" @click="deactivate">Deactivate</Button>
        </template>

        <div class="space-y-4">
            <!-- Whether a quotation raised today would read this list, said in one line with the dates. -->
            <div
                class="rounded-lg border px-3 py-2.5 text-sm"
                :class="{
                    'border-emerald-200 bg-emerald-50 text-emerald-900': standing.tone === 'success',
                    'border-amber-200 bg-amber-50 text-amber-900': standing.tone === 'warning',
                    'border-sky-200 bg-sky-50 text-sky-900': standing.tone === 'info',
                    'border-rose-200 bg-rose-50 text-rose-900': standing.tone === 'danger',
                    'border-slate-200 bg-slate-50 text-ink-800': standing.tone === 'neutral',
                }"
            >
                <span class="font-medium">{{ standing.label }}</span> · valid from {{ date(list.valid_from) }}
                <template v-if="list.valid_to"> to {{ date(list.valid_to) }}</template><template v-else>, open-ended</template>.
                {{ standing.text }}
            </div>

            <div
                v-if="overlapping.length"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                <p class="font-medium">Another active list covers some of the same days.</p>
                <p class="mt-0.5 text-xs">
                    A quotation raised on such a day could read either one. End one before the other starts, or deactivate it:
                    <template v-for="(other, index) in overlapping" :key="other.id"><span v-if="index">, </span><Link :href="`/price-lists/${other.id}`" class="font-medium underline">{{ other.code }}</Link> ({{ date(other.valid_from) }}<template v-if="other.valid_to"> to {{ date(other.valid_to) }}</template><template v-else>, open-ended</template>)</template>.
                </p>
            </div>

            <Card
                v-for="group in byProduct"
                :key="group.product.product_code"
                :title="`${group.product.product_code} — ${group.product.product_name}`"
                subtitle="The break with the highest starting quantity at or below the ordered quantity applies"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'min_qty', label: 'From quantity', align: 'right' },
                        { key: 'rate_per_m', label: 'Rate per 1,000 pcs', align: 'right' },
                        { key: 'description', label: 'Note' },
                    ]"
                    :rows="group.breaks"
                    row-key="id"
                    dense
                    empty="No breaks."
                >
                    <template #cell:min_qty="{ value }">{{ pcs(value) }} pcs</template>
                    <template #cell:rate_per_m="{ value }">{{ rate(value, list.currency) }}</template>
                    <template #cell:description="{ value }"><span :class="value ? '' : 'text-ink-400'">{{ value || '—' }}</span></template>
                </DataTable>
            </Card>

            <Card v-if="lines.length === 0">
                <p class="text-sm text-ink-500">This price list has no rates yet. Edit it to add one.</p>
            </Card>
        </div>
    </AppLayout>
</template>
