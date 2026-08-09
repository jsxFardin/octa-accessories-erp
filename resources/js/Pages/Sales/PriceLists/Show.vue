<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, pcs, ratePerM } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    list: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
});

/** Grouped by product, because the breaks only make sense read together. */
const byProduct = computed(() => {
    const groups = new Map();

    for (const line of props.lines) {
        const key = line.product_code ?? "—";

        if (!groups.has(key)) groups.set(key, { product: line, breaks: [] });

        groups.get(key).breaks.push(line);
    }

    return [...groups.values()];
});
</script>

<template>
    <AppLayout>
        <Head :title="list.code" />

        <template #title>{{ list.code }}</template>
        <template #subtitle>
            {{ list.name }} · {{ list.customer }} · {{ list.currency }} ·
            {{ date(list.valid_from) }} — {{ list.valid_to ? date(list.valid_to) : "open-ended" }}
        </template>

        <template #actions>
            <Badge :tone="list.is_active ? 'success' : 'neutral'" :label="list.is_active ? 'Active' : 'Inactive'" />
            <Button v-if="can('price_list.update')" size="sm" :href="`/price-lists/${list.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <Card
                v-for="group in byProduct"
                :key="group.product.product_code"
                :title="`${group.product.product_code} — ${group.product.product_name}`"
                :padded="false"
            >
                <DataTable
                    :columns="[
                        { key: 'min_qty', label: 'From quantity', align: 'right' },
                        { key: 'rate_per_m', label: 'Rate / 1,000', align: 'right' },
                        { key: 'description', label: 'Note' },
                    ]"
                    :rows="group.breaks"
                    row-key="id"
                    dense
                    empty="No breaks."
                >
                    <template #cell:min_qty="{ value }">{{ pcs(value) }} pcs</template>
                    <template #cell:rate_per_m="{ value }">{{ ratePerM(value) }}</template>
                </DataTable>
            </Card>

            <Card v-if="lines.length === 0">
                <p class="text-sm text-ink-500">This price list has no lines.</p>
            </Card>

            <p class="text-xs text-ink-500">
                The applicable rate is the break with the highest "from quantity" at or below the
                ordered quantity.
            </p>
        </div>
    </AppLayout>
</template>
