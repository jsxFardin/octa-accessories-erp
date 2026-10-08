<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import CodeName from '@/Components/Ui/CodeName.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { useConfirm } from '@/composables/useConfirm';
import { date, isoDate, money, number, pcs, qty, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    supplier: Object,
    items: Array,
    purchaseOrders: Array,
    contacts: { type: Array, default: () => [] },
    /** `{ currency, payment_term }`, as names. */
    terms: { type: Object, default: () => ({}) },
    /** The account in numbers, in the factory's currency: on order, owed, last order. */
    stats: { type: Object, default: () => ({}) },
    /** Active materials, for linking one to this supplier. */
    materials: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const mayEdit = can('supplier.update');

/*
 * Contacts, the materials they sell and how to reach them are kept here, on the supplier they
 * belong to — as a customer's contacts and addresses are on the customer. All three were on
 * the record and none could be added from anywhere a buyer would look.
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
        contactForm.put(`/suppliers/${props.supplier.id}/contacts/${editingContact.value.id}`, done);
    } else {
        contactForm.post(`/suppliers/${props.supplier.id}/contacts`, done);
    }
}

async function removeContact(contact) {
    if (!await confirm({
        title: `Remove ${contact.name}?`,
        message: 'They will no longer be listed for this supplier. Orders already sent are not changed.',
        confirmLabel: 'Remove',
    })) return;

    router.delete(`/suppliers/${props.supplier.id}/contacts/${contact.id}`, { preserveScroll: true });
}

const materialOpen = ref(false);
const editingMaterial = ref(null);
const materialForm = useForm({ item_id: '', supplier_code: '', last_rate: '', currency_id: '', lead_time_days: '', moq: '' });

/** A material is linked once: the picker offers only the ones not on the list yet. */
const linkable = computed(() => {
    const taken = new Set(props.items.map((row) => row.item_id));

    return props.materials.filter((material) => !taken.has(material.id));
});

function openMaterial(row = null) {
    editingMaterial.value = row;

    materialForm.clearErrors();
    materialForm.defaults({
        item_id: row?.item_id ?? '',
        supplier_code: row?.supplier_code ?? '',
        last_rate: row?.last_rate ?? '',
        // A new rate is most likely in the currency the supplier trades in.
        currency_id: row?.currency_id ?? props.supplier.currency_id ?? '',
        lead_time_days: row?.lead_time_days ?? '',
        moq: row?.moq ?? '',
    });
    materialForm.reset();

    materialOpen.value = true;
}

function saveMaterial() {
    const done = { preserveScroll: true, onSuccess: () => (materialOpen.value = false) };
    // An empty box is "none of its own", not zero.
    const blankAsNull = (data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value]));

    if (editingMaterial.value) {
        materialForm.transform(blankAsNull).put(`/suppliers/${props.supplier.id}/items/${editingMaterial.value.id}`, done);
    } else {
        materialForm.transform(blankAsNull).post(`/suppliers/${props.supplier.id}/items`, done);
    }
}

async function removeMaterial(row) {
    if (!await confirm({
        title: `Remove ${row.code} from ${props.supplier.code}?`,
        message: 'Their code, last rate, lead time and minimum order for this material are forgotten. Orders already placed are not changed.',
        confirmLabel: 'Remove',
    })) return;

    router.delete(`/suppliers/${props.supplier.id}/items/${row.id}`, { preserveScroll: true });
}

/* Email, phone and address: three fields, changed here without the whole edit form. */
const reachOpen = ref(false);
const reachForm = useForm({ email: '', phone: '', address: '' });

function openReach() {
    reachForm.clearErrors();
    reachForm.defaults({
        email: props.supplier.email ?? '',
        phone: props.supplier.phone ?? '',
        address: props.supplier.address ?? '',
    });
    reachForm.reset();

    reachOpen.value = true;
}

function saveReach() {
    reachForm.put(`/suppliers/${props.supplier.id}/reach`, { preserveScroll: true, onSuccess: () => (reachOpen.value = false) });
}

/**
 * An order may be *drafted* against any supplier, but one cannot be submitted to a supplier
 * nobody has approved. Offering the action to an unapproved supplier would walk the buyer into
 * that wall after they had typed the lines, so the page says so up front instead.
 */
const canOrder = computed(
    () => props.supplier.is_active && props.supplier.is_approved && can('purchase_order.create'),
);

const orderHref = computed(() => `/purchase-orders/create?supplier=${props.supplier.id}`);

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

    if (diff === null) return { text: 'Never', note: 'No order yet', quiet: true };

    return { text: date(props.stats.last_order_on), note: diff === 0 ? 'today' : `${plural(diff, 'day')} ago`, quiet: false };
});

/** When the next delivery is due — and that it is late, when it is. */
const nextDelivery = computed(() => {
    const diff = daysFromToday(props.stats.next_expected_on);

    if (diff === null) return null;
    if (diff < 0) return { text: `${plural(diff, 'day')} late`, late: true };

    return { text: diff === 0 ? 'due today' : `in ${plural(diff, 'day')}`, late: false };
});

const TILE = 'rounded-lg border p-3.5 shadow-sm';
const TILE_LINK = `${TILE} transition hover:border-brand-300 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none`;
</script>

<template>
    <AppLayout :crumb="supplier.code">
        <Head :title="supplier.code" />

        <template #title>{{ supplier.code }} · {{ supplier.name }}</template>
        <template #subtitle>
            {{ supplier.country }}<template v-if="terms.currency"> · trades in {{ terms.currency }}</template><template v-if="terms.payment_term"> · {{ terms.payment_term }}</template>
        </template>

        <template #actions>
            <Badge
                :tone="supplier.is_active ? 'success' : 'neutral'"
                :label="supplier.is_active ? 'Active' : 'Inactive'"
            />
            <Badge
                :tone="supplier.is_approved ? 'success' : 'warning'"
                :label="supplier.is_approved ? 'Approved' : 'Not approved'"
            />
            <Button v-if="canOrder" size="sm" variant="primary" :href="orderHref">Create purchase order</Button>
            <Button v-if="can('supplier.update')" size="sm" :href="`/suppliers/${supplier.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <div
                v-if="!supplier.is_approved"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                <div class="min-w-0 flex-1 basis-64">
                    <p class="font-medium">This supplier is not approved.</p>
                    <p class="mt-0.5 text-xs">
                        An order can be drafted against them, but it cannot be submitted until Purchasing
                        approves them.
                    </p>
                </div>
                <Button v-if="can('supplier.update')" size="sm" :href="`/suppliers/${supplier.id}/edit`">Open to approve</Button>
            </div>

            <!--
                The account in four figures. The page used to be two tables in the top third of
                an otherwise blank screen: nothing said what was on order, what was owed, or
                when anything was last bought. Factory currency throughout — orders and bills
                come in several.
            -->
            <section aria-label="Account at a glance" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Link :href="`/purchase-orders?supplier=${supplier.id}`" :class="[TILE_LINK, 'border-slate-200 bg-white']">
                    <p class="text-xs text-ink-600">On order</p>
                    <p class="mt-1 text-xl font-semibold" :class="stats.open_order_count ? 'text-ink-900' : 'text-ink-400'">{{ money(stats.open_order_value) }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ pcs(stats.open_order_count) }} open {{ stats.open_order_count === 1 ? 'order' : 'orders' }}</p>
                </Link>
                <div :class="[TILE, nextDelivery?.late ? 'border-rose-200 bg-rose-50/60' : 'border-slate-200 bg-white']">
                    <p class="text-xs text-ink-600">Next delivery due</p>
                    <p class="mt-1 text-xl font-semibold" :class="nextDelivery?.late ? 'text-rose-700' : nextDelivery ? 'text-ink-900' : 'text-ink-400'">{{ nextDelivery ? date(stats.next_expected_on) : 'None due' }}</p>
                    <p class="mt-1 text-xs" :class="nextDelivery?.late ? 'text-rose-800' : 'text-ink-500'">{{ nextDelivery?.text ?? 'No open order has an expected date' }}</p>
                </div>
                <Link :href="`/supplier-bills?supplier=${supplier.id}`" :class="[TILE_LINK, stats.overdue > 0 ? 'border-rose-200 bg-rose-50/60' : 'border-slate-200 bg-white']">
                    <p class="text-xs text-ink-600">We owe</p>
                    <p class="mt-1 text-xl font-semibold" :class="stats.overdue > 0 ? 'text-rose-700' : stats.outstanding > 0 ? 'text-ink-900' : 'text-ink-400'">{{ money(stats.outstanding) }}</p>
                    <p class="mt-1 text-xs" :class="stats.overdue > 0 ? 'text-rose-800' : 'text-ink-500'">
                        {{ stats.overdue > 0 ? `${money(stats.overdue)} past due` : `${pcs(stats.unpaid_bill_count)} unpaid ${stats.unpaid_bill_count === 1 ? 'bill' : 'bills'}` }}
                    </p>
                </Link>
                <div :class="[TILE, 'border-slate-200 bg-white']">
                    <p class="text-xs text-ink-600">Last order</p>
                    <p class="mt-1 text-xl font-semibold" :class="lastOrder.quiet ? 'text-ink-400' : 'text-ink-900'">{{ lastOrder.text }}</p>
                    <p class="mt-1 text-xs text-ink-500">{{ lastOrder.note }}</p>
                </div>
            </section>

            <!-- Work on the left; who they are and what was agreed on the right. -->
            <div class="grid gap-4 lg:grid-cols-3 lg:items-start">
                <div class="min-w-0 space-y-4 lg:col-span-2">
                    <Card title="Purchase orders" subtitle="Most recent first" :padded="false">
                        <template #actions>
                            <Link :href="`/purchase-orders?supplier=${supplier.id}`" class="text-xs font-medium text-brand-700 hover:underline">All orders</Link>
                        </template>
                        <DataTable
                            :columns="[
                                { key: 'number', label: 'Number' },
                                { key: 'order_date', label: 'Ordered' },
                                { key: 'expected_date', label: 'Expected' },
                                { key: 'total', label: 'Value', align: 'right' },
                                { key: 'status', label: 'Status' },
                            ]"
                            :rows="purchaseOrders"
                            row-key="id"
                            :row-href="(row) => `/purchase-orders/${row.id}`"
                            empty="No purchase orders."
                            dense
                        >
                            <template #empty>
                                <EmptyState
                                    icon="purchase-order"
                                    title="Nothing has been ordered from this supplier"
                                    :description="canOrder
                                        ? 'An order opens with this supplier already chosen, and pulls from any approved requisition still waiting to be bought.'
                                        : supplier.is_approved
                                            ? 'Nothing has been bought from them yet.'
                                            : 'They must be approved before an order can be submitted to them.'"
                                    :action-label="canOrder ? 'Create purchase order' : null"
                                    :action-href="canOrder ? orderHref : null"
                                />
                            </template>

                            <template #cell:number="{ row, value }"><Link :href="`/purchase-orders/${row.id}`" class="doc-link-quiet">{{ value ?? '(draft)' }}</Link></template>
                            <template #cell:order_date="{ value }">{{ date(value) }}</template>
                            <template #cell:expected_date="{ value }">{{ value ? date(value) : '—' }}</template>
                            <!-- The order's own currency, never the factory's by default; it is in the figure, so it needs no column. -->
                            <template #cell:total="{ row, value }"><span class="tnum">{{ money(value, row.currency) }}</span></template>
                            <template #cell:status="{ value }"><Badge :status="value" /></template>
                        </DataTable>
                    </Card>

                    <Card
                        title="Materials they supply"
                        rule="BR-26"
                        :subtitle="`Lead time and minimum order are per material; without one, the supplier's ${pcs(supplier.lead_time_days)} days apply (shown in grey)`"
                        :padded="false"
                    >
                        <template #actions>
                            <Button v-if="mayEdit" size="sm" data-add-material @click="openMaterial()">Add material</Button>
                        </template>
                        <DataTable
                            :columns="[
                                { key: 'code', label: 'Material' },
                                { key: 'last_rate', label: 'Last rate', align: 'right' },
                                { key: 'lead_time_days', label: 'Lead time', align: 'right' },
                                { key: 'moq', label: 'Minimum order', align: 'right' },
                                ...(mayEdit ? [{ key: 'actions', label: '', align: 'right' }] : []),
                            ]"
                            :rows="items"
                            row-key="id"
                            empty="No materials linked yet."
                            dense
                        >
                            <template #empty>
                                <EmptyState
                                    icon="item"
                                    title="No materials linked yet"
                                    description="Link what this supplier sells, with their code, rate, lead time and minimum order, so a purchase order can fill them in."
                                    :action-label="mayEdit ? 'Add material' : null"
                                    @action="openMaterial()"
                                />
                            </template>
                            <template #cell:actions="{ row }">
                                <span class="-mr-1.5 inline-flex">
                                    <Button size="sm" variant="ghost" :aria-label="`Edit ${row.code}`" @click="openMaterial(row)">Edit</Button>
                                    <Button size="sm" variant="danger-quiet" :aria-label="`Remove ${row.code}`" @click="removeMaterial(row)">Remove</Button>
                                </span>
                            </template>
                            <!-- The name gives way before a column does: the minimum order was pushed out of the card. -->
                            <template #cell:code="{ row }">
                                <span class="flex max-w-56 2xl:max-w-none" :title="row.name">
                                    <Link v-if="can('item.view_any')" :href="`/items/${row.item_id}`" class="min-w-0 hover:underline"><CodeName class="max-w-full" :code="row.code" :name="row.name" /></Link>
                                    <CodeName v-else class="max-w-full" :code="row.code" :name="row.name" />
                                </span>
                                <!-- Their code sits under ours: it names the same thing, and a column of its own pushed the row's actions out of the card. -->
                                <span v-if="row.supplier_code" class="block text-xs text-ink-500">Their code {{ row.supplier_code }}</span>
                            </template>
                            <template #cell:last_rate="{ row, value }"><span class="tnum">{{ value ? money(value, row.currency) : '—' }}</span></template>
                            <!-- No lead time of its own: the supplier's applies, and is shown as such rather than as a dash. -->
                            <template #cell:lead_time_days="{ value }">
                                <span v-if="value !== null && value !== undefined" class="tnum">{{ pcs(value) }} days</span>
                                <span v-else class="tnum text-ink-500" title="The supplier's usual lead time; this material has none of its own">{{ pcs(supplier.lead_time_days) }} days</span>
                            </template>
                            <template #cell:moq="{ value }"><span :class="value ? 'tnum' : 'text-ink-500'">{{ value ? qty(value) : 'None' }}</span></template>
                        </DataTable>
                    </Card>
                </div>

                <div class="min-w-0 space-y-4">
                    <Card title="Terms and contact">
                        <template #actions>
                            <Button v-if="mayEdit" size="sm" data-edit-reach @click="openReach">Edit contact details</Button>
                        </template>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-ink-500">Trades in</dt><dd class="text-right">{{ terms.currency ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-500">Payment terms</dt><dd class="text-right">{{ terms.payment_term ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-500">Usual lead time</dt><dd class="tnum text-right">{{ pcs(supplier.lead_time_days) }} days</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-500">Rating</dt><dd class="tnum text-right">{{ supplier.rating !== null ? `${number(supplier.rating, 1)} of 5` : 'Not rated' }}</dd></div>
                            <div class="flex justify-between gap-3 border-t border-slate-100 pt-2">
                                <dt class="text-ink-500">Email</dt>
                                <dd class="min-w-0 truncate text-right"><a v-if="supplier.email" :href="`mailto:${supplier.email}`" class="doc-link-quiet">{{ supplier.email }}</a><template v-else>—</template></dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-500">Phone</dt>
                                <dd class="text-right"><a v-if="supplier.phone" :href="`tel:${supplier.phone}`" class="doc-link-quiet">{{ supplier.phone }}</a><template v-else>—</template></dd>
                            </div>
                            <div class="flex justify-between gap-3"><dt class="shrink-0 text-ink-500">Address</dt><dd class="text-right">{{ supplier.address || '—' }}</dd></div>
                        </dl>
                    </Card>

                    <Card title="Contacts" subtitle="The primary one is who a buyer rings first" :padded="false">
                        <template #actions>
                            <Button v-if="mayEdit" size="sm" data-add-contact @click="openContact()">Add contact</Button>
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
                                        <span v-if="!contact.email && !contact.phone" class="text-ink-500">No email or phone recorded</span>
                                    </p>
                                </div>

                                <div v-if="mayEdit" class="-mr-1.5 flex shrink-0">
                                    <Button size="sm" variant="ghost" @click="openContact(contact)">Edit</Button>
                                    <Button size="sm" variant="danger-quiet" @click="removeContact(contact)">Remove</Button>
                                </div>
                            </li>
                            <li v-if="contacts.length === 0" class="px-3 py-4 text-sm text-ink-600">
                                No named contact. The email and phone above are the supplier's own.
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
        </div>

        <Modal v-model:open="contactOpen" :title="editingContact ? 'Edit contact' : 'Add contact'" :subtitle="`A person at ${supplier.name}.`" :dirty="contactForm.isDirty">
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveContact">
                <FormField label="Name" :error="contactForm.errors.name" required>
                    <TextInput v-model="contactForm.name" />
                </FormField>

                <FormField label="Designation" :error="contactForm.errors.designation" hint="Sales manager, export desk…">
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
                        The first person to ring at this supplier
                    </label>
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="contactForm.processing" :disabled="!contactForm.name" @click="saveContact">
                    {{ editingContact ? 'Save contact' : 'Add contact' }}
                </Button>
            </template>
        </Modal>

        <Modal
            v-model:open="materialOpen"
            width="max-w-2xl"
            :title="editingMaterial ? `Edit ${editingMaterial.code}` : 'Add material'"
            :subtitle="editingMaterial ? editingMaterial.name : `Something ${supplier.name} sells to us.`"
            :dirty="materialForm.isDirty"
        >
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveMaterial">
                <FormField v-if="!editingMaterial" label="Material" class="sm:col-span-2" :error="materialForm.errors.item_id" required>
                    <SelectInput
                        v-model="materialForm.item_id"
                        :options="linkable"
                        value-key="id"
                        label-key="code"
                        hint-key="name"
                        placeholder="Search by code or name"
                    />
                </FormField>

                <FormField label="Their code" hint="What the supplier calls it; printed on the purchase order." :error="materialForm.errors.supplier_code">
                    <TextInput v-model="materialForm.supplier_code" />
                </FormField>

                <FormField label="Lead time (days)" :hint="`Leave empty to use the supplier's usual ${pcs(supplier.lead_time_days)} days.`" :error="materialForm.errors.lead_time_days">
                    <TextInput v-model="materialForm.lead_time_days" type="number" numeric min="0" max="365" />
                </FormField>

                <FormField label="Last rate" hint="Per the material's own unit." :error="materialForm.errors.last_rate">
                    <TextInput v-model="materialForm.last_rate" type="number" numeric min="0" step="any" />
                </FormField>

                <FormField label="Rate currency" :error="materialForm.errors.currency_id">
                    <SelectInput v-model="materialForm.currency_id" :options="currencies" value-key="id" label-key="code" hint-key="name" placeholder="— select —" />
                </FormField>

                <FormField label="Minimum order" hint="In the material's own unit. Leave empty if there is none." :error="materialForm.errors.moq">
                    <TextInput v-model="materialForm.moq" type="number" numeric min="0" step="any" />
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="materialForm.processing" :disabled="!materialForm.item_id" @click="saveMaterial">
                    {{ editingMaterial ? 'Save material' : 'Add material' }}
                </Button>
            </template>
        </Modal>

        <Modal v-model:open="reachOpen" title="Edit contact details" :subtitle="`How to reach ${supplier.name} itself. People are kept under Contacts.`" :dirty="reachForm.isDirty">
            <form class="space-y-3" @submit.prevent="saveReach">
                <FormField label="Email" :error="reachForm.errors.email">
                    <TextInput v-model="reachForm.email" type="email" />
                </FormField>

                <FormField label="Phone" :error="reachForm.errors.phone">
                    <TextInput v-model="reachForm.phone" type="tel" />
                </FormField>

                <FormField label="Address" hint="Printed on purchase orders sent to them." :error="reachForm.errors.address">
                    <TextInput v-model="reachForm.address" />
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="reachForm.processing" @click="saveReach">Save contact details</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
