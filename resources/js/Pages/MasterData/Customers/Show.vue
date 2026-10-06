<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, isoDate, money, pcs, pct, titleCase, todayIso } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    customer: Object,
    contacts: { type: Array, default: () => [] },
    /** The account in numbers: on order, owed, exposure against the limit, last order, quotes out. */
    stats: { type: Object, default: () => ({}) },
    products: Array,
    openOrders: Array,
    inquiries: { type: Array, default: () => [] },
    quotations: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    priceLists: { type: Array, default: () => [] },
    addresses: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();

/*
 * Contacts, addresses and brands are edited here rather than in Setup. All three belong to
 * exactly this customer. Contacts had no screen at all: the inquiry form read them, and
 * nothing could add one.
 */
const contactOpen = ref(false);
const editingContact = ref(null);
const contactForm = useForm({ name: '', designation: '', email: '', phone: '', is_primary: false });

function openContact(contact = null) {
    editingContact.value = contact;

    contactForm.clearErrors();
    contactForm.defaults({
        name: contact?.name ?? '',
        designation: contact?.designation ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        is_primary: contact ? Boolean(contact.is_primary) : props.contacts.length === 0,
    });
    contactForm.reset();

    contactOpen.value = true;
}

function saveContact() {
    const done = { preserveScroll: true, onSuccess: () => (contactOpen.value = false) };

    if (editingContact.value) {
        contactForm.put(`/customers/${props.customer.id}/contacts/${editingContact.value.id}`, done);
    } else {
        contactForm.post(`/customers/${props.customer.id}/contacts`, done);
    }
}

async function removeContact(contact) {
    if (!await confirm({
        title: `Remove ${contact.name}?`,
        message: 'Inquiries that already name this person keep the record; new ones will not offer them.',
        confirmLabel: 'Remove',
    })) return;

    router.delete(`/customers/${props.customer.id}/contacts/${contact.id}`, { preserveScroll: true });
}

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

/*
 * BR-46 — exposure is what they owe plus what they have on order, against the limit. The
 * meter wears the state: brand while there is room, amber past 80%, rose over the limit.
 */
const exposureShare = computed(() => (props.stats.credit_limit > 0 ? (props.stats.exposure / props.stats.credit_limit) * 100 : null));
const exposureTone = computed(() => {
    if (exposureShare.value === null) return { bar: 'bg-brand-500', text: 'text-ink-900' };
    if (exposureShare.value > 100) return { bar: 'bg-rose-500', text: 'text-rose-700' };
    if (exposureShare.value > 80) return { bar: 'bg-amber-500', text: 'text-amber-700' };

    return { bar: 'bg-brand-500', text: 'text-ink-900' };
});

/** Whole calendar days from today to a date, negative when it has passed. */
function daysFromToday(value) {
    const iso = isoDate(value);

    if (!iso) return null;

    const [y, m, d] = iso.split('-').map(Number);
    const [ty, tm, td] = todayIso().split('-').map(Number);

    return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
}

const plural = (n, word) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? word : `${word}s`}`;

const lastOrder = computed(() => {
    const diff = daysFromToday(props.stats.last_order_on);

    if (diff === null) return { text: 'Never', note: null, quiet: true };

    return { text: date(props.stats.last_order_on), note: diff === 0 ? 'today' : `${plural(diff, 'day')} ago`, quiet: diff < -90 };
});

/** A price list's standing today: current, not yet, lapsed, or switched off. */
function listStanding(list) {
    if (!list.is_active) return { label: 'Inactive', tone: 'neutral' };

    const from = daysFromToday(list.valid_from);
    const to = list.valid_to ? daysFromToday(list.valid_to) : null;

    if (from !== null && from > 0) return { label: `Starts ${date(list.valid_from)}`, tone: 'info' };
    if (to !== null && to < 0) return { label: `Lapsed ${date(list.valid_to)}`, tone: 'danger' };
    if (to !== null && to <= 30) return { label: `Ends ${date(list.valid_to)}`, tone: 'warning' };

    return { label: 'Current', tone: 'success' };
}

const kindLabel = computed(() => titleCase(props.customer.kind ?? ''));
</script>

<template>
    <AppLayout>
        <Head :title="customer.code" />

        <template #title>{{ customer.code }} · {{ customer.name }}</template>
        <template #subtitle>
            {{ kindLabel }}<template v-if="customer.country"> · {{ customer.country }}</template><template v-if="customer.currency"> · trades in {{ customer.currency.code }}</template><template v-if="customer.payment_term"> · {{ customer.payment_term.name }}</template>
        </template>

        <template #actions>
            <Badge
                :tone="customer.is_active ? 'success' : 'neutral'"
                :label="customer.is_active ? 'Active' : 'Inactive'"
            />
            <Button v-if="can('inquiry.create') && customer.is_active" size="sm" variant="primary" :href="inquiryHref">
                New inquiry
            </Button>
            <Button v-if="can('quotation.create') && customer.is_active" size="sm" :href="`/quotations/create?customer=${customer.id}`">
                New quotation
            </Button>
            <Button v-if="can('customer.update')" size="sm" :href="`/customers/${customer.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <div
                v-if="!customer.is_active"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                <p class="font-medium">This customer is inactive.</p>
                <p class="mt-0.5 text-xs">
                    Its history is intact, but it will not appear when anyone searches for a customer on an
                    inquiry, quotation or order. Edit it and tick <span class="font-medium">Active</span> to
                    make it selectable again.
                </p>
            </div>

            <!-- The account in six figures. Base currency throughout: the limit is stated in it. -->
            <section aria-label="Account at a glance" class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
                <Link :href="`/sales-orders?customer=${customer.id}`" class="rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm transition hover:border-brand-300 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none">
                    <p class="text-xs text-ink-600">On order</p>
                    <p class="mt-1 text-xl font-semibold" :class="stats.open_order_count ? 'text-ink-900' : 'text-ink-400'">{{ stats.open_order_count ? money(stats.open_order_value) : 'Nothing' }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ pcs(stats.open_order_count) }} open {{ stats.open_order_count === 1 ? 'order' : 'orders' }}</p>
                </Link>
                <Link :href="`/sales-invoices?customer=${customer.id}`" class="rounded-lg border p-3.5 shadow-sm transition hover:border-brand-300 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none" :class="stats.overdue > 0 ? 'border-rose-200 bg-rose-50/60' : 'border-slate-200 bg-white'">
                    <p class="text-xs text-ink-600">Outstanding</p>
                    <p class="mt-1 text-xl font-semibold" :class="stats.overdue > 0 ? 'text-rose-700' : stats.outstanding > 0 ? 'text-ink-900' : 'text-ink-400'">{{ stats.outstanding > 0 ? money(stats.outstanding) : 'Nothing' }}</p>
                    <p class="mt-1 text-xs" :class="stats.overdue > 0 ? 'text-rose-800' : 'text-ink-500'">{{ stats.overdue > 0 ? `${money(stats.overdue)} past due` : 'Nothing past due' }}</p>
                </Link>
                <div class="rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm">
                    <p class="text-xs text-ink-600">Credit exposure</p>
                    <p class="mt-1 text-xl font-semibold" :class="exposureTone.text">{{ money(stats.exposure) }}</p>
                    <template v-if="stats.credit_limit > 0">
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <div class="h-full rounded-full" :class="exposureTone.bar" :style="{ width: `${Math.min(100, exposureShare)}%` }" />
                        </div>
                        <p class="mt-1 text-xs text-ink-500">{{ pct(exposureShare, 0) }} of {{ money(stats.credit_limit) }} limit</p>
                    </template>
                    <p v-else class="mt-1 text-xs text-ink-500">No credit limit set</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm">
                    <p class="text-xs text-ink-600">Last order</p>
                    <p class="mt-1 text-xl font-semibold" :class="lastOrder.quiet ? 'text-ink-400' : 'text-ink-900'">{{ lastOrder.text }}</p>
                    <p class="mt-1 text-xs" :class="lastOrder.quiet && lastOrder.note ? 'text-amber-700' : 'text-ink-500'">{{ lastOrder.note ?? 'No order yet' }}</p>
                </div>
                <Link :href="`/quotations?customer=${customer.id}&status=sent`" class="rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm transition hover:border-brand-300 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none">
                    <p class="text-xs text-ink-600">Quotes out</p>
                    <p class="mt-1 text-xl font-semibold" :class="stats.quotations_out ? 'text-ink-900' : 'text-ink-400'">{{ pcs(stats.quotations_out) }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ pcs(stats.inquiries_open) }} {{ stats.inquiries_open === 1 ? 'inquiry' : 'inquiries' }} in play</p>
                </Link>
                <div class="rounded-lg border border-slate-200 bg-white p-3.5 shadow-sm">
                    <p class="text-xs text-ink-600">Invoiced to date</p>
                    <p class="mt-1 text-xl font-semibold" :class="stats.invoice_count ? 'text-ink-900' : 'text-ink-400'">{{ stats.invoice_count ? money(stats.lifetime_invoiced) : 'Nothing' }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ pcs(stats.invoice_count) }} {{ stats.invoice_count === 1 ? 'invoice' : 'invoices' }}</p>
                </div>
            </section>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- Who to talk to. -->
                <Card class="lg:col-span-2" title="Contacts" subtitle="The people an inquiry names; the primary one is suggested first" :padded="false">
                    <template #actions>
                        <Button v-if="can('customer.update')" size="sm" @click="openContact()">Add contact</Button>
                    </template>

                    <ul class="divide-y divide-slate-100">
                        <li v-for="contact in contacts" :key="contact.id" class="flex flex-wrap items-start justify-between gap-3 p-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-ink-900">{{ contact.name }}</span>
                                    <span v-if="contact.designation" class="text-xs text-ink-500">{{ contact.designation }}</span>
                                    <Badge v-if="contact.is_primary" tone="success" label="Primary" />
                                </div>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-ink-600">
                                    <a v-if="contact.email" :href="`mailto:${contact.email}`" class="hover:underline">{{ contact.email }}</a>
                                    <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="hover:underline">{{ contact.phone }}</a>
                                    <span v-if="!contact.email && !contact.phone" class="text-ink-400">No email or phone recorded</span>
                                </p>
                            </div>

                            <div v-if="can('customer.update')" class="flex shrink-0 gap-1.5">
                                <Button size="sm" @click="openContact(contact)">Edit</Button>
                                <Button size="sm" variant="danger" @click="removeContact(contact)">Remove</Button>
                            </div>
                        </li>

                        <li v-if="contacts.length === 0" class="p-6 text-center text-sm text-ink-500">
                            Nobody recorded yet. An inquiry can name a contact once one exists.
                        </li>
                    </ul>
                </Card>

                <!-- The settings: what the rules read. -->
                <Card title="Terms and limits" rule="BR-21 · BR-44 · BR-46">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Trades in</dt><dd class="text-right">{{ customer.currency?.code ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Payment terms</dt><dd class="text-right">{{ customer.payment_term?.name ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Credit limit</dt><dd class="tnum text-right">{{ money(customer.credit_limit) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Minimum order value</dt><dd class="tnum text-right">{{ money(customer.min_order_value) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Delivery tolerance</dt><dd class="tnum text-right">−{{ pct(customer.under_tolerance_pct, 1) }} / +{{ pct(customer.over_tolerance_pct, 1) }}</dd></div>
                        <div class="flex justify-between gap-3 border-t border-slate-100 pt-2"><dt class="text-ink-500">Email</dt><dd class="min-w-0 truncate text-right">{{ customer.email || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Phone</dt><dd class="text-right">{{ customer.phone || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">BIN</dt><dd class="tnum text-right">{{ customer.bin_no || '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">TIN</dt><dd class="tnum text-right">{{ customer.tin_no || '—' }}</dd></div>
                    </dl>
                </Card>
            </div>

            <!-- The pipeline, both ends: what they asked for and what was offered. -->
            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Inquiries" subtitle="Most recent first" :padded="false">
                    <template #actions>
                        <Link :href="`/inquiries?customer=${customer.id}`" class="text-xs font-medium text-brand-700 hover:underline">All inquiries</Link>
                    </template>
                    <DataTable
                        :columns="[
                            { key: 'number', label: 'Number' },
                            { key: 'inquiry_date', label: 'Received' },
                            { key: 'required_by', label: 'Required by' },
                            { key: 'status', label: 'Status' },
                        ]"
                        :rows="inquiries"
                        row-key="id"
                        :row-href="(row) => `/inquiries/${row.id}`"
                        empty="Nothing asked for yet."
                        dense
                    >
                        <template #cell:number="{ row, value }"><Link :href="`/inquiries/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}</Link></template>
                        <template #cell:inquiry_date="{ value }">{{ date(value) }}</template>
                        <template #cell:required_by="{ value }">{{ value ? date(value) : '—' }}</template>
                        <template #cell:status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>

                <Card title="Quotations" subtitle="Most recent first" :padded="false">
                    <template #actions>
                        <Link :href="`/quotations?customer=${customer.id}`" class="text-xs font-medium text-brand-700 hover:underline">All quotations</Link>
                    </template>
                    <DataTable
                        :columns="[
                            { key: 'number', label: 'Number' },
                            { key: 'quotation_date', label: 'Date' },
                            { key: 'total', label: 'Value', align: 'right' },
                            { key: 'status', label: 'Status' },
                        ]"
                        :rows="quotations"
                        row-key="id"
                        :row-href="(row) => `/quotations/${row.id}`"
                        empty="Nothing quoted yet."
                        dense
                    >
                        <template #cell:number="{ row, value }">
                            <Link :href="`/quotations/${row.id}`" class="doc-link-quiet">{{ value ?? '(unnumbered)' }}<span v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span></Link>
                        </template>
                        <template #cell:quotation_date="{ value }">{{ date(value) }}</template>
                        <template #cell:total="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                        <template #cell:status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>
            </div>

            <Card title="Open order book" :padded="false">
                <template #actions>
                    <Link :href="`/sales-orders?customer=${customer.id}`" class="text-xs font-medium text-brand-700 hover:underline">All orders</Link>
                </template>
                <DataTable
                    :columns="[
                        { key: 'so_number', label: 'Order' },
                        { key: 'product_code', label: 'Product' },
                        { key: 'ordered_qty', label: 'Ordered', align: 'right' },
                        { key: 'delivered_qty', label: 'Delivered', align: 'right' },
                        { key: 'delivered_pct', label: 'Progress', align: 'right', width: '8rem' },
                        { key: 'promised_date', label: 'Promised' },
                    ]"
                    :rows="openOrders"
                    row-key="sales_order_line_id"
                    :row-href="(row) => `/sales-orders/${row.sales_order_id}`"
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

                    <template #cell:so_number="{ row, value }"><Link :href="`/sales-orders/${row.sales_order_id}`" class="doc-link-quiet">{{ value }}</Link></template>
                    <template #cell:ordered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:delivered_pct="{ value }">
                        <span class="inline-flex items-center justify-end gap-2">
                            <span class="h-1.5 w-14 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                <span class="block h-full rounded-full" :class="Number(value) >= 100 ? 'bg-emerald-500' : 'bg-brand-500'" :style="{ width: `${Math.min(100, Number(value) || 0)}%` }" />
                            </span>
                            <span class="w-8 text-right tnum">{{ pct(value, 0) }}</span>
                        </span>
                    </template>
                    <template #cell:promised_date="{ value }">{{ date(value) }}</template>
                </DataTable>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card title="Unpaid invoices" subtitle="Oldest due date first" :padded="false">
                    <DataTable
                        :columns="[
                            { key: 'number', label: 'Invoice' },
                            { key: 'due_date', label: 'Due' },
                            { key: 'balance', label: 'Balance', align: 'right' },
                            { key: 'status', label: 'Status' },
                        ]"
                        :rows="invoices"
                        row-key="id"
                        :row-href="(row) => `/sales-invoices/${row.id}`"
                        empty="Nothing unpaid."
                        dense
                    >
                        <template #cell:number="{ row, value }"><Link :href="`/sales-invoices/${row.id}`" class="doc-link-quiet">{{ value ?? '(draft)' }}</Link></template>
                        <template #cell:due_date="{ value }">
                            <span :class="daysFromToday(value) < 0 ? 'font-medium text-rose-700' : ''">{{ date(value) }}</span>
                        </template>
                        <template #cell:balance="{ row }"><span class="tnum">{{ money(row.total - row.received_amount, row.currency) }}</span></template>
                        <template #cell:status="{ value }"><Badge :status="value" /></template>
                    </DataTable>
                </Card>

                <Card title="Price lists" subtitle="Agreed rates; a quotation reads the current one" :padded="false">
                    <template #actions>
                        <Button v-if="can('price_list.create')" size="sm" :href="`/price-lists/create?customer=${customer.id}`">New price list</Button>
                    </template>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="list in priceLists" :key="list.id" class="flex items-center justify-between gap-3 px-3 py-2">
                            <div class="min-w-0">
                                <Link :href="`/price-lists/${list.id}`" class="doc-link-quiet">{{ list.code }}</Link>
                                <span class="ml-1 text-ink-600">{{ list.name }}</span>
                                <p class="text-xs text-ink-500">{{ list.currency }} · from {{ date(list.valid_from) }}<template v-if="list.valid_to"> to {{ date(list.valid_to) }}</template></p>
                            </div>
                            <Badge :tone="listStanding(list).tone" :label="listStanding(list).label" />
                        </li>
                        <li v-if="priceLists.length === 0" class="px-3 py-6 text-center text-ink-500">
                            No agreed rates. Quotations are priced from the cost sheet alone.
                        </li>
                    </ul>
                </Card>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
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
                                <p class="mt-0.5 text-xs text-ink-500">
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
            </div>

            <Card title="Products" subtitle="One product, one customer" :padded="false">
                <template #actions>
                    <Button v-if="can('product.create') && customer.is_active" size="sm" :href="productHref">New product</Button>
                </template>
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

        <Modal v-model:open="contactOpen" :title="editingContact ? 'Edit contact' : 'Add contact'" subtitle="The primary contact is the one a new inquiry suggests.">
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveContact">
                <FormField label="Name" :error="contactForm.errors.name" required>
                    <TextInput v-model="contactForm.name" />
                </FormField>

                <FormField label="Designation" :error="contactForm.errors.designation" hint="Merchandising manager, sourcing head…">
                    <TextInput v-model="contactForm.designation" />
                </FormField>

                <FormField label="Email" :error="contactForm.errors.email">
                    <TextInput v-model="contactForm.email" type="email" />
                </FormField>

                <FormField label="Phone" :error="contactForm.errors.phone">
                    <TextInput v-model="contactForm.phone" type="tel" />
                </FormField>

                <FormField label="Primary" class="sm:col-span-2">
                    <label class="flex h-9 items-center gap-2 text-sm text-ink-700">
                        <input v-model="contactForm.is_primary" type="checkbox" class="form-checkbox">
                        Suggest this person first on a new inquiry
                    </label>
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="contactForm.processing"
                    :disabled="!contactForm.name"
                    @click="saveContact"
                >
                    {{ editingContact ? 'Save contact' : 'Add contact' }}
                </Button>
            </template>
        </Modal>

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
            subtitle="One code across every customer; a product is filed under it."
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
