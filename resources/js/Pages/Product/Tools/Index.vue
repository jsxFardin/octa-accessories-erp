<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import SlideOver from '@/Components/Ui/SlideOver.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { baseCurrency, date, pcs, todayIso } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useGuardedAction } from '@/composables/useGuardedAction';

const props = defineProps({
    tools: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    kinds: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    specs: { type: Array, default: () => [] },
    /** Who may own a tool, and the stock items a mould can be carried as. */
    customers: { type: Array, default: () => [] },
    toolItems: { type: Array, default: () => [] },
});

/** The stored keys, in the words the plate room uses. */
const KIND_LABELS = {
    flexo_plate: 'Flexo plate',
    screen: 'Screen',
    offset_plate: 'Offset plate',
    cutting_die: 'Cutting die',
    embossing_die: 'Embossing die',
    cad_pattern: 'CAD pattern',
    mould: 'Mould',
};

const STATUS_LABELS = {
    in_production: 'Being made',
    available: 'Available',
    in_use: 'On a machine',
    worn: 'Worn',
    scrapped: 'Retired',
};

const STATUS_TONES = { in_production: 'info', available: 'success', in_use: 'progress', worn: 'warning', scrapped: 'neutral' };

const kindOptions = computed(() => props.kinds.map((kind) => ({ value: kind, label: KIND_LABELS[kind] ?? kind })));
const statusOptions = computed(() => props.statuses.map((status) => ({ value: status, label: STATUS_LABELS[status] ?? status })));

/** What a person sets. "On a machine" is set when a job takes the tool; "Retired" has its own button. */
const settableStatuses = computed(() => statusOptions.value.filter((option) => ['in_production', 'available', 'worn'].includes(option.value)));

const specOptions = computed(() => props.specs.map((spec) => ({
    value: spec.id,
    label: `${spec.code} · specification v${spec.version_no}`,
    hint: `${spec.name}${spec.status === 'draft' ? ' (draft)' : ''}`,
})));

const columns = [
    { key: 'code', label: 'Code', sort: true },
    { key: 'kind', label: 'Kind', sort: true },
    { key: 'product', label: 'Made for' },
    { key: 'location', label: 'Kept at' },
    { key: 'life_impressions', label: 'Life (impressions)', align: 'right' },
    { key: 'remaining', label: 'Left', align: 'right' },
    { key: 'status', label: 'Status', sort: true },
    { key: 'act', label: '', align: 'right' },
];

function remaining(tool) {
    if (tool.life_impressions === null) return null;

    return Math.max(0, Number(tool.life_impressions) - Number(tool.used_impressions));
}

/*
 * Register and edit. The list was read-only, so a plate, screen or die could only exist if
 * someone put it in the database by hand — while releasing a job checks its tools against
 * this list.
 */
const mayCreate = can('tool.create');
const mayUpdate = can('tool.update');

const blank = () => ({
    code: '',
    kind: null,
    product_spec_id: null,
    colour_index: null,
    location: '',
    made_on: todayIso(),
    cost: null,
    life_impressions: null,
    status: 'available',
    cavity_count: null,
    owner_customer_id: null,
    item_id: null,
});

const form = useForm(blank());
const editing = ref(null);
const open = ref(false);

function openCreate() {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    open.value = true;
}

function openEdit(tool) {
    editing.value = tool;
    form.defaults({
        code: tool.code,
        kind: tool.kind,
        product_spec_id: tool.product_spec_id,
        colour_index: tool.colour_index,
        location: tool.location ?? '',
        made_on: tool.made_on,
        cost: tool.cost === null ? null : Number(tool.cost),
        life_impressions: tool.life_impressions,
        status: tool.status === 'in_use' ? 'available' : tool.status,
        cavity_count: tool.cavity_count,
        owner_customer_id: tool.owner_customer_id,
        item_id: tool.item_id,
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

/** Only printing tools are cut per colour. */
const perColour = computed(() => ['flexo_plate', 'screen', 'offset_plate'].includes(form.kind));

/** A mould is defined by how many pieces one shot gives; a plate is not. */
const isMould = computed(() => form.kind === 'mould');

const blockedBy = computed(() => {
    if (!form.code.trim()) return 'Enter the tool code.';
    if (!form.kind) return 'Choose what kind of tool this is.';

    return null;
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    form.transform((data) => ({ ...data, colour_index: perColour.value ? data.colour_index : null }));

    if (editing.value) form.put(`/tools/${editing.value.id}`, options);
    else form.post('/tools', options);
}

const { busy, run } = useGuardedAction();

function retire(tool) {
    run(`retire-${tool.id}`, {
        title: `Retire ${tool.code}?`,
        message: 'It can no longer be chosen for a job, and it cannot be brought back. Its history stays on the jobs it ran.',
        confirmLabel: 'Retire tool',
        tone: 'danger',
    }, (done) => router.post(`/tools/${tool.id}/retire`, {}, { preserveScroll: true, ...done }));
}
</script>

<template>
    <AppLayout>
        <Head title="Tools" />

        <template #title>Tools</template>
        <template #subtitle>Plates, screens, dies and patterns, with the impressions each has left</template>

        <template #actions>
            <Button v-if="mayCreate" variant="primary" size="sm" data-new-tool @click="openCreate">Register a tool</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'kind', label: 'Kind', options: kindOptions },
                    { key: 'status', label: 'Status', options: statusOptions },
                ]"
                placeholder="Search by code or where it is kept…"
            />

            <DataTable :columns="columns" :rows="tools" row-key="id" empty="No tools registered.">
                <template #cell:code="{ value }"><span class="font-medium text-ink-900">{{ value }}</span></template>
                <template #cell:product="{ row }">
                    <Link v-if="row.product" :href="`/products/${row.product.id}`" class="doc-link-quiet">
                        {{ row.product.code }}
                    </Link>
                    <span v-if="row.product" class="text-ink-500"> · v{{ row.spec_version }}</span>
                    <span v-else class="text-ink-500">Any product</span>
                </template>
                <template #cell:kind="{ row, value }">
                    {{ KIND_LABELS[value] ?? value }}
                    <span v-if="row.colour_index" class="text-ink-500"> · colour {{ row.colour_index }}</span>
                    <span v-if="row.cavity_count" class="text-ink-500"> · {{ row.cavity_count }} {{ row.cavity_count === 1 ? 'cavity' : 'cavities' }}</span>
                    <Badge v-if="row.owner" tone="info" :label="`Owned by ${row.owner}`" class="ml-1" />
                </template>
                <template #cell:location="{ value }">{{ value || '—' }}</template>
                <template #cell:life_impressions="{ value }">{{ value === null ? 'Not set' : pcs(value) }}</template>
                <template #cell:remaining="{ row }">
                    <span v-if="remaining(row) === null" class="text-ink-500">—</span>
                    <span v-else :class="remaining(row) <= 0 ? 'font-medium text-rose-700' : ''" class="tnum">{{ pcs(remaining(row)) }}</span>
                </template>
                <template #cell:status="{ value }">
                    <Badge :tone="STATUS_TONES[value] ?? 'neutral'" :label="STATUS_LABELS[value] ?? value" />
                </template>
                <template #cell:act="{ row }">
                    <div v-if="mayUpdate && row.status !== 'scrapped'" class="flex justify-end gap-1">
                        <Button size="sm" variant="ghost" :aria-label="`Edit ${row.code}`" @click="openEdit(row)">Edit</Button>
                        <Button
                            size="sm" variant="ghost"
                            :aria-label="`Retire ${row.code}`"
                            :loading="busy === `retire-${row.id}`"
                            :disabled="busy !== null || row.status === 'in_use'"
                            :title="row.status === 'in_use' ? 'On a machine: finish or hold the job using it first.' : null"
                            data-retire
                            @click="retire(row)"
                        >Retire</Button>
                    </div>
                </template>
                <template #empty>
                    <EmptyState
                        icon="tool"
                        title="No tools yet"
                        :description="mayCreate
                            ? 'Register each plate, screen and die here. A job that needs a tool cannot be released until that tool is on this list and available.'
                            : 'Plates, screens and dies are registered here by the pre-press or planning team.'"
                        :action-label="mayCreate ? 'Register a tool' : null"
                        :filtered="Object.entries(filters ?? {}).some(([key, value]) => key !== 'sort' && value)"
                        @action="openCreate"
                    />
                </template>
            </DataTable>
        </Card>

        <SlideOver
            v-model:open="open"
            :title="editing ? `Edit ${editing.code}` : 'Register a tool'"
            :subtitle="editing?.status === 'in_use' ? 'This tool is on a machine, so its status cannot be changed here.' : null"
            :dirty="form.isDirty"
        >
            <form class="space-y-4" @submit.prevent="blockedBy === null && submit()">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Code" :error="form.errors.code" required hint="As marked on the tool.">
                        <TextInput v-model="form.code" maxlength="40" placeholder="TOOL-L-FLX-02" />
                    </FormField>
                    <FormField label="Kind" :error="form.errors.kind" required>
                        <SelectInput v-model="form.kind" :options="kindOptions" placeholder="Choose…" />
                    </FormField>
                </div>

                <FormField
                    label="Made for"
                    :error="form.errors.product_spec_id"
                    hint="The product specification this tool was cut for. Leave blank for a general tool."
                >
                    <SelectInput v-model="form.product_spec_id" :options="specOptions" hint-key="hint" placeholder="Any product" clearable />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField v-if="perColour" label="Colour number" :error="form.errors.colour_index" hint="Which colour of the design it prints.">
                        <TextInput v-model="form.colour_index" type="number" min="1" max="20" numeric />
                    </FormField>
                    <FormField v-if="isMould" label="Cavities" :error="form.errors.cavity_count" required hint="Pieces one shot gives.">
                        <TextInput v-model="form.cavity_count" type="number" min="1" numeric />
                    </FormField>
                    <FormField label="Owned by" :error="form.errors.owner_customer_id" hint="Empty: the factory owns it.">
                        <SelectInput v-model="form.owner_customer_id" :options="customers" value-key="id" label-key="name" placeholder="The factory" clearable />
                    </FormField>
                    <FormField label="Carried as stock item" :error="form.errors.item_id" hint="When the tool is also an item on the master.">
                        <SelectInput v-model="form.item_id" :options="toolItems" value-key="id" label-key="name" hint-key="code" placeholder="Not a stock item" clearable />
                    </FormField>
                    <FormField label="Kept at" :error="form.errors.location">
                        <TextInput v-model="form.location" maxlength="80" placeholder="Plate room, rack B" />
                    </FormField>
                    <FormField label="Made on" :error="form.errors.made_on">
                        <DateInput v-model="form.made_on" />
                    </FormField>
                    <FormField :label="`Cost (${baseCurrency()})`" :error="form.errors.cost">
                        <TextInput v-model="form.cost" type="number" min="0" step="any" numeric placeholder="0.00" />
                    </FormField>
                    <FormField
                        label="Life (impressions)"
                        :error="form.errors.life_impressions"
                        :hint="editing && Number(editing.used_impressions) > 0
                            ? `It has run ${pcs(editing.used_impressions)} so far.`
                            : 'How many impressions it is good for before it must be remade.'"
                    >
                        <TextInput v-model="form.life_impressions" type="number" min="1" numeric />
                    </FormField>
                    <FormField v-if="editing?.status !== 'in_use'" label="Status" :error="form.errors.status" required>
                        <SelectInput v-model="form.status" :options="settableStatuses" :placeholder="null" />
                    </FormField>
                </div>

                <p v-if="blockedBy" id="tool-blocked" class="text-xs text-ink-600">{{ blockedBy }}</p>
            </form>

            <template #footer>
                <Button @click="open = false">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing || blockedBy !== null"
                    :aria-describedby="blockedBy ? 'tool-blocked' : null"
                    data-save-tool
                    @click="submit"
                >{{ editing ? 'Save changes' : 'Register tool' }}</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
