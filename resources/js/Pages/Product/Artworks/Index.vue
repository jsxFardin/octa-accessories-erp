<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/plugins/permissions';

const props = defineProps({
    artworks: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    designers: { type: Array, default: () => [] },
});

const createOpen = ref(false);

const form = useForm({ product_id: '', code: '', title: '', designer_id: '' });

const chosenProduct = computed(
    () => props.products.find((product) => product.id === Number(form.product_id)) ?? null,
);

/**
 * Artwork is always artwork *for* a product, so the product supplies the code stem and the
 * title. Both stay editable — the designer knows the house convention better than we do.
 */
watch(() => form.product_id, () => {
    const product = chosenProduct.value;

    if (!product) return;

    form.code = form.code || `AW-${product.code}`;
    form.title = form.title || product.name;
});

function create() {
    // The redirect lands on the artwork, where version 1 gets uploaded.
    form.post('/artworks', {
        onSuccess: () => {
            createOpen.value = false;
            form.reset();
        },
    });
}

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'title', label: 'Title' },
    { key: 'product', label: 'Product' },
    { key: 'customer', label: 'Customer' },
    { key: 'version_count', label: 'Versions', align: 'center' },
    { key: 'latest_version', label: 'Latest' },
    { key: 'approved_version', label: 'Approved' },
];
</script>

<template>
    <AppLayout>
        <Head title="Artwork" />

        <template #title>Artwork</template>
        <template #subtitle>Gate 1 — production may only run against an approved version</template>

        <template #actions>
            <Button v-if="can('artwork.create')" variant="primary" @click="createOpen = true">New artwork</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'state', label: 'Gate', options: [
                    { value: 'awaiting_approval', label: 'Awaiting customer' },
                    { value: 'unapproved', label: 'No approved version' },
                ] }]"
                placeholder="Search artwork code or title…"
            />

            <DataTable :columns="columns" :rows="artworks" row-key="id" :row-href="(row) => `/artworks/${row.id}`"
                       empty="No artwork matches these filters.">
                <template #cell:code="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:product="{ row }">
                    <span v-if="row.product"><span class="font-medium">{{ row.product.code }}</span> {{ row.product.name }}</span>
                </template>
                <template #cell:latest_version="{ value }">
                    <span v-if="value" class="flex items-center gap-1">
                        <span class="tnum text-xs">v{{ value.version_no }}</span>
                        <Badge :status="value.status" />
                    </span>
                </template>
                <template #cell:approved_version="{ value }">
                    <Badge v-if="value" tone="success" :label="`v${value.version_no}`" />
                    <!-- No approved version is the blocking condition, so it reads as one -->
                    <Badge v-else tone="danger" label="Blocked" />
                </template>
            </DataTable>
        </Card>

        <Modal
            v-model:open="createOpen"
            title="New artwork"
            subtitle="Creating the artwork is step one; version 1 is uploaded on the next screen."
        >
            <form class="space-y-3" @submit.prevent="create">
                <FormField label="Product" :error="form.errors.product_id" required>
                    <SelectInput
                        v-model="form.product_id"
                        placeholder="— select —"
                        :options="products"
                        value-key="id"
                        label-key="code"
                    />
                    <p v-if="chosenProduct" class="mt-1 text-[11px] text-ink-500">
                        {{ chosenProduct.name }}
                        <span v-if="chosenProduct.customer_name"> · {{ chosenProduct.customer_name }}</span>
                    </p>
                </FormField>

                <FormField label="Artwork code" :error="form.errors.code" required>
                    <TextInput v-model="form.code" placeholder="AW-…" />
                </FormField>

                <FormField label="Title" :error="form.errors.title" required>
                    <TextInput v-model="form.title" />
                </FormField>

                <FormField label="Designer" :error="form.errors.designer_id">
                    <SelectInput v-model="form.designer_id" :options="designers" value-key="id" label-key="name" />
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="!form.product_id || !form.code || !form.title"
                    @click="create"
                >
                    Create artwork
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
