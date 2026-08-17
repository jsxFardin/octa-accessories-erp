<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ orders: { type: Array, default: () => [] } });

const form = useForm({ sales_order_id: null });

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
                    <select v-model="form.sales_order_id" class="w-full rounded-md border-slate-300 text-sm">
                        <option :value="null" disabled>Choose an open order…</option>
                        <option v-for="order in orders" :key="order.id" :value="order.id">
                            {{ order.number ?? `(draft #${order.id})` }} — {{ order.customer_name }}
                        </option>
                    </select>
                </FormField>

                <div>
                    <Button type="submit" variant="primary" :disabled="form.processing">Create draft</Button>
                </div>
            </form>
        </Card>
    </AppLayout>
</template>
