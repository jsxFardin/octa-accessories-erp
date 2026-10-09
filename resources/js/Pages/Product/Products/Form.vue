<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import ItemSummaryRail from '@/Components/MasterData/ItemSummaryRail.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { productSections } from '@/Pages/MasterData/Items/itemSections';

const props = defineProps({
    product: Object, preselectedCustomer: Number,
    customers: Array, brands: Array, routings: Array, productTypes: Array, statuses: Array, cutTypes: Array,
    families: Array, groups: Array, uoms: Array, warehouses: Array, familyAttributes: Object,
    garmentTypes: Array, materialBases: Array, specScopes: Array, variantAxes: Array, valuationMethods: Array,
});

const isEdit = computed(() => Boolean(props.product));

/*
 * A product is a finished good on the item master with a make profile beside it. The sections
 * are shared with the Materials screen, in the order the work is done: name it, say who it is
 * for, classify it, specify it, say how it is counted, how it is made, how it is costed.
 */
const sections = computed(() => {
    const built = productSections(props, { isEdit: isEdit.value });

    // Arriving from a customer's page: that customer, already chosen.
    if (!isEdit.value && props.preselectedCustomer) {
        const customer = built[1].fields.find((field) => field.key === 'customer_id');
        if (customer) customer.default = props.preselectedCustomer;
    }

    // An existing product's buyer is fixed (P1): the field shows, and does not change.
    if (isEdit.value && props.product?.customer_id) {
        const customer = built[1].fields.find((field) => field.key === 'customer_id');
        if (customer) customer.disabled = true;
    }

    return built;
});

const initial = computed(() => ({ ...(props.product ?? {}), spec_scope: props.product?.spec_scope ?? (props.product?.customer_id === null && isEdit.value ? 'standard' : 'buyer') }));
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${product.code ?? ''}` : 'New product'" />

        <template #title>{{ isEdit ? `Edit ${product.name ?? product.code}` : 'New product' }}</template>
        <template #subtitle>{{ isEdit ? 'The item and its make profile.' : 'A finished good the factory makes. Saved as a draft; its page then walks through setup.' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="initial"
            :action="isEdit ? `/products/${product.id}` : '/products'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create product'"
            cancel-href="/products"
            layout="columns"
        >
            <template #rail="{ form }">
                <ItemSummaryRail :form="form" :options="props" for-product :is-edit="isEdit" />
            </template>
        </ResourceForm>
    </AppLayout>
</template>
