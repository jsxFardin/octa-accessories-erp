<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ResourceForm from '@/Components/Ui/ResourceForm.vue';
import ItemSummaryRail from '@/Components/MasterData/ItemSummaryRail.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { itemSections } from './itemSections';

const props = defineProps({
    item: Object,
    categories: Array, uoms: Array, suppliers: Array, warehouses: Array, families: Array, groups: Array, customers: Array,
    familyAttributes: Object,
    itemTypes: Array, makeOrBuy: Array, garmentTypes: Array, specScopes: Array, materialBases: Array, variantAxes: Array, valuationMethods: Array, chargeBases: Array,
});

const isEdit = computed(() => Boolean(props.item));

const sections = computed(() => itemSections(props, { isEdit: isEdit.value }));
</script>

<template>
    <AppLayout>
        <Head :title="isEdit ? `Edit ${item.code ?? ''}` : 'New item'" />

        <template #title>{{ isEdit ? `Edit ${item.name ?? item.code}` : 'New item' }}</template>
        <template #subtitle>{{ isEdit ? 'The item master record.' : 'Anything the factory buys, makes, consumes or charges for. A made item also gets a product page.' }}</template>

        <ResourceForm
            :sections="sections"
            :initial="item ?? {}"
            :action="isEdit ? `/items/${item.id}` : '/items'"
            :method="isEdit ? 'put' : 'post'"
            :submit-label="isEdit ? 'Save changes' : 'Create item'"
            cancel-href="/items"
            layout="columns"
        >
            <template #rail="{ form }">
                <ItemSummaryRail :form="form" :options="props" :is-edit="isEdit" />
            </template>
        </ResourceForm>
    </AppLayout>
</template>
