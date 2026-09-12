<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    customer: Object,
    products: Array,
    openOrders: Array,
    outstanding: Number,
    addresses: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();

/*
 * Addresses and brands are edited here rather than in Setup. Both belong to exactly this
 * customer, and the delivery address in particular is not decoration: a packing list resolves
 * where the cartons go through it, so a customer without one reaches dispatch with nowhere to
 * send the goods.
 */
const addressOpen = ref(false);
const editingAddress = ref(null);

const addressForm = useForm({
    label: '',
    kind: 'delivery',
    line1: '',
    line2: '',
    city: '',
    district: '',
    postcode: '',
    country: 'Bangladesh',
    transit_days: 1,
    route_zone: '',
    is_default: false,
});

const addressKinds = [
    { value: 'delivery', label: 'Delivery' },
    { value: 'billing', label: 'Billing' },
    { value: 'both', label: 'Billing and delivery' },
];

function openAddress(address = null) {
    editingAddress.value = address;

    addressForm.clearErrors();
    addressForm.defaults({
        label: address?.label ?? '',
        kind: address?.kind ?? 'delivery',
        line1: address?.line1 ?? '',
        line2: address?.line2 ?? '',
        city: address?.city ?? '',
        district: address?.district ?? '',
        postcode: address?.postcode ?? '',
        country: address?.country ?? 'Bangladesh',
        transit_days: address?.transit_days ?? 1,
        route_zone: address?.route_zone ?? '',
        is_default: Boolean(address?.is_default),
    });
    addressForm.reset();

    addressOpen.value = true;
}

function saveAddress() {
    const done = { preserveScroll: true, onSuccess: () => (addressOpen.value = false) };

    if (editingAddress.value) {
        addressForm.put(`/customers/${props.customer.id}/addresses/${editingAddress.value.id}`, done);
    } else {
        addressForm.post(`/customers/${props.customer.id}/addresses`, done);
    }
}

async function removeAddress(address) {
    if (!await confirm({
        title: `Remove “${address.label}”?`,
        message: 'Documents already addressed to it keep their copy; new ones will not offer it.',
        confirmLabel: 'Remove',
    })) return;

    router.delete(`/customers/${props.customer.id}/addresses/${address.id}`, { preserveScroll: true });
}

const brandOpen = ref(false);
const editingBrand = ref(null);

const brandForm = useForm({ code: '', name: '', is_active: true });

function openBrand(brand = null) {
    editingBrand.value = brand;

    brandForm.clearErrors();
    brandForm.defaults({
        code: brand?.code ?? '',
        name: brand?.name ?? '',
        is_active: brand ? Boolean(brand.is_active) : true,
    });
    brandForm.reset();

    brandOpen.value = true;
}

function saveBrand() {
    const done = { preserveScroll: true, onSuccess: () => (brandOpen.value = false) };

    if (editingBrand.value) {
        brandForm.put(`/customers/${props.customer.id}/brands/${editingBrand.value.id}`, done);
    } else {
        brandForm.post(`/customers/${props.customer.id}/brands`, done);
    }
}

async function removeBrand(brand) {
    if (!await confirm({
        title: `Remove ${brand.code}?`,
        message: 'Only possible while no product is filed under it. Untick Active to retire one that is.',
        confirmLabel: 'Remove',
    })) return;

    router.delete(`/customers/${props.customer.id}/brands/${brand.id}`, { preserveScroll: true });
}

/**
 * A customer that is not active is invisible to every picker in the application, so a record
 * that looks perfectly normal here quietly cannot be quoted or ordered against. That has to
 * be said on the page, not discovered in an empty dropdown.
 */
const inquiryHref = computed(() => `/inquiries/create?customer=${props.customer.id}`);
const productHref = computed(() => `/products/create?customer=${props.customer.id}`);
</script>

<template>
    <AppLayout>
        <Head :title="customer.code" />

        <template #title>{{ customer.code }} · {{ customer.name }}</template>
        <template #subtitle>{{ customer.country }}</template>

        <template #actions>
            <Badge
                :tone="customer.is_active ? 'success' : 'neutral'"
                :label="customer.is_active ? 'Active' : 'Inactive'"
            />
            <Button v-if="can('inquiry.create') && customer.is_active" size="sm" variant="primary" :href="inquiryHref">
                New inquiry
            </Button>
            <Button v-if="can('customer.update')" size="sm" :href="`/customers/${customer.id}/edit`">Edit</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-3">
            <div
                v-if="!customer.is_active"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 lg:col-span-3"
            >
                <p class="font-medium">This customer is inactive.</p>
                <p class="mt-0.5 text-xs">
                    Its history is intact, but it will not appear when anyone searches for a customer on an
                    inquiry, quotation or order. Edit it and tick <span class="font-medium">Active</span> to
                    make it selectable again.
                </p>
            </div>

            <Card title="Commercial guard rails" rule="BR-21 · BR-44 · BR-46">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-500">Credit limit</dt><dd class="tnum">{{ money(customer.credit_limit) }}</dd></div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">Outstanding</dt>
                        <dd class="tnum font-medium" :class="outstanding > customer.credit_limit && customer.credit_limit > 0 ? 'text-rose-600' : ''">
                            {{ money(outstanding) }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-ink-500">Minimum order value</dt><dd class="tnum">{{ money(customer.min_order_value) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-500">Delivery tolerance</dt><dd class="tnum">−{{ customer.under_tolerance_pct }}% / +{{ customer.over_tolerance_pct }}%</dd></div>
                </dl>
            </Card>

            <Card class="lg:col-span-2" title="Open order book" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'so_number', label: 'Order' },
                        { key: 'product_code', label: 'Product' },
                        { key: 'ordered_qty', label: 'Ordered', align: 'right' },
                        { key: 'delivered_qty', label: 'Delivered', align: 'right' },
                        { key: 'delivered_pct', label: '%', align: 'right' },
                        { key: 'promised_date', label: 'Promised' },
                    ]"
                    :rows="openOrders"
                    row-key="sales_order_line_id"
                    empty="No open orders."
                    dense
                >
                    <template #empty>
                        <EmptyState
                            icon="order"
                            title="Nothing on order for this customer"
                            :description="customer.is_active
                                ? 'Work reaches this page once an inquiry is quoted and the quotation is converted into an order.'
                                : 'Nothing can be ordered while the customer is inactive.'"
                            :action-label="can('inquiry.create') && customer.is_active ? 'New inquiry' : null"
                            :action-href="can('inquiry.create') && customer.is_active ? inquiryHref : null"
                        />
                    </template>

                    <template #cell:ordered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                </DataTable>
            </Card>

            <Card
                class="lg:col-span-2"
                title="Addresses"
                subtitle="Where cartons are sent and invoices are addressed. A packing list resolves its destination through the default delivery address."
                :padded="false"
            >
                <template #actions>
                    <Button v-if="can('customer.update')" size="sm" @click="openAddress()">Add address</Button>
                </template>

                <ul class="divide-y divide-slate-100">
                    <li v-for="address in addresses" :key="address.id" class="flex flex-wrap items-start justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-ink-900">{{ address.label }}</span>
                                <Badge tone="neutral" :label="titleCase(address.kind)" />
                                <Badge v-if="address.is_default" tone="success" label="Default" />
                            </div>
                            <p class="mt-0.5 text-xs text-ink-600">
                                {{ [address.line1, address.line2, address.city, address.district, address.postcode, address.country].filter(Boolean).join(', ') }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-ink-500">
                                Transit {{ address.transit_days }} day(s)<span v-if="address.route_zone"> · zone {{ address.route_zone }}</span>
                            </p>
                        </div>

                        <div v-if="can('customer.update')" class="flex shrink-0 gap-1.5">
                            <Button size="sm" @click="openAddress(address)">Edit</Button>
                            <Button size="sm" variant="danger" @click="removeAddress(address)">Remove</Button>
                        </div>
                    </li>

                    <li v-if="addresses.length === 0" class="p-6 text-center text-sm text-ink-500">
                        No address on file. Nothing can be dispatched to this customer until one exists.
                    </li>
                </ul>
            </Card>

            <Card
                title="Brands"
                subtitle="The label this customer's garments carry; products are filed under it"
                :padded="false"
            >
                <template #actions>
                    <Button v-if="can('customer.update')" size="sm" @click="openBrand()">Add brand</Button>
                </template>

                <ul class="divide-y divide-slate-100">
                    <li v-for="brand in brands" :key="brand.id" class="flex items-start justify-between gap-3 p-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-ink-900">{{ brand.code }}</span>
                                <Badge v-if="!brand.is_active" tone="neutral" label="Retired" />
                            </div>
                            <p class="text-xs text-ink-500">{{ brand.name }}</p>
                        </div>

                        <div v-if="can('customer.update')" class="flex shrink-0 gap-1.5">
                            <Button size="sm" @click="openBrand(brand)">Edit</Button>
                            <Button size="sm" variant="danger" @click="removeBrand(brand)">Remove</Button>
                        </div>
                    </li>

                    <li v-if="brands.length === 0" class="p-6 text-center text-sm text-ink-500">
                        No brands yet.
                    </li>
                </ul>
            </Card>

            <Card class="lg:col-span-3" title="Products" subtitle="One product, one customer" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Name' },
                        { key: 'product_type', label: 'Type' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :rows="products"
                    row-key="id"
                    :row-href="(row) => `/products/${row.id}`"
                    empty="No products defined for this customer."
                    dense
                >
                    <template #empty>
                        <EmptyState
                            icon="product"
                            title="No products yet"
                            description="A product belongs to exactly one customer, and a quotation line can only name one of theirs. Nothing can be quoted until at least one exists."
                            :action-label="can('product.create') ? 'New product' : null"
                            :action-href="can('product.create') ? productHref : null"
                        />
                    </template>

                    <template #cell:product_type="{ value }">{{ titleCase(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>
        </div>
        <Modal
            v-model:open="addressOpen"
            width="max-w-2xl"
            :title="editingAddress ? 'Edit address' : 'Add address'"
            subtitle="The default delivery address is what a packing list falls back to when an order names none."
        >
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveAddress">
                <FormField label="Label" hint="How it is picked on an order — “Head office”, “Gazipur unit”." :error="addressForm.errors.label" required>
                    <TextInput v-model="addressForm.label" />
                </FormField>

                <FormField label="Used for" :error="addressForm.errors.kind" required>
                    <SelectInput v-model="addressForm.kind" :placeholder="null" :options="addressKinds" />
                </FormField>

                <FormField label="Address line 1" class="sm:col-span-2" :error="addressForm.errors.line1" required>
                    <TextInput v-model="addressForm.line1" />
                </FormField>

                <FormField label="Address line 2" class="sm:col-span-2" :error="addressForm.errors.line2">
                    <TextInput v-model="addressForm.line2" />
                </FormField>

                <FormField label="City" :error="addressForm.errors.city">
                    <TextInput v-model="addressForm.city" />
                </FormField>

                <FormField label="District" :error="addressForm.errors.district">
                    <TextInput v-model="addressForm.district" />
                </FormField>

                <FormField label="Postcode" :error="addressForm.errors.postcode">
                    <TextInput v-model="addressForm.postcode" />
                </FormField>

                <FormField label="Country" :error="addressForm.errors.country" required>
                    <SelectInput v-model="addressForm.country" :placeholder="null" :options="countries" />
                </FormField>

                <FormField label="Transit days" rule="BR-29" hint="Added to the promised date for a delivery here." :error="addressForm.errors.transit_days" required>
                    <TextInput v-model="addressForm.transit_days" type="number" numeric min="0" />
                </FormField>

                <FormField label="Route zone" :error="addressForm.errors.route_zone">
                    <TextInput v-model="addressForm.route_zone" />
                </FormField>

                <FormField label="Default" class="sm:col-span-2">
                    <label class="flex h-9 items-center gap-2 text-sm text-ink-700">
                        <input v-model="addressForm.is_default" type="checkbox" class="form-checkbox">
                        Use this address when an order names none
                    </label>
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="addressForm.processing"
                    :disabled="!addressForm.label || !addressForm.line1"
                    @click="saveAddress"
                >
                    {{ editingAddress ? 'Save address' : 'Add address' }}
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="brandOpen"
            :title="editingBrand ? 'Edit brand' : 'Add brand'"
            subtitle="Codes are unique across every customer — a product's filing reads off this one."
        >
            <form class="space-y-3" @submit.prevent="saveBrand">
                <FormField label="Code" :error="brandForm.errors.code" required>
                    <TextInput v-model="brandForm.code" placeholder="NB-ESS" />
                </FormField>

                <FormField label="Name" :error="brandForm.errors.name" required>
                    <TextInput v-model="brandForm.name" placeholder="Nordic Basics Essentials" />
                </FormField>

                <FormField label="Active">
                    <label class="flex h-9 items-center gap-2 text-sm text-ink-700">
                        <input v-model="brandForm.is_active" type="checkbox" class="form-checkbox">
                        Offered when a product is filed
                    </label>
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="brandForm.processing"
                    :disabled="!brandForm.code || !brandForm.name"
                    @click="saveBrand"
                >
                    {{ editingBrand ? 'Save brand' : 'Add brand' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
