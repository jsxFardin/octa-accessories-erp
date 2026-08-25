<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    orders: { type: Array, default: () => [] },
    /** The order dispatch came from, resolved server-side against the packable list. */
    preselectOrderId: { type: Number, default: null },
});

const form = useForm({ sales_order_id: props.preselectOrderId ?? null });

const preselected = computed(
    () => props.orders.find((order) => order.id === props.preselectOrderId) ?? null,
);

const orderOptions = computed(() => props.orders.map((order) => ({
    value: order.id,
    label: order.number ?? `(draft #${order.id})`,
    hint: order.customer_name,
})));

function submit() {
    form.post('/packing-lists');
}
</script>

<template>
    <AppLayout>
        <Head title="New packing list" />

        <template #title>New packing list</template>
        <template #subtitle>Pick the order; cartons are built from available FG on the next screen</template>

        <Card>
            <form class="flex max-w-xl flex-col gap-4" @submit.prevent="submit">
                <p
                    v-if="preselected"
                    class="rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-900"
                >
                    Packing
                    <Link :href="`/sales-orders/${preselected.id}`" class="font-medium underline">
                        order {{ preselected.number ?? `#${preselected.id}` }}</Link>
                    for {{ preselected.customer_name }}. Choose a different order below if that is not the one.
                </p>

                <FormField label="Sales order" :error="form.errors.sales_order_id" required>
                    <SelectInput
                        v-model="form.sales_order_id"
                        :options="orderOptions"
                        hint-key="hint"
                        placeholder="Choose an open order…"
                    />
                </FormField>

                <div>
                    <Button type="submit" variant="primary" :loading="form.processing" :disabled="form.processing || !form.sales_order_id">Create draft</Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
