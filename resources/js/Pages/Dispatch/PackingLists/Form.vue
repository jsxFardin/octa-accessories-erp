<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ orders: { type: Array, default: () => [] } });

const form = useForm({ sales_order_id: null });

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
