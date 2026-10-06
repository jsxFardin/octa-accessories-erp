<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import Icon from '@/Components/Ui/Icon.vue';
import CodeName from '@/Components/Ui/CodeName.vue';
import { mm, money, number, pcs, pct, qty, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useGuardedAction } from '@/composables/useGuardedAction';
import RuleHint from '@/Components/Ui/RuleHint.vue';

const props = defineProps({
    product: { type: Object, required: true },
    /** `[{ key, label, state: done|todo|attention, detail, unlocks, action }]`, in working order. */
    setup: { type: Array, default: () => [] },
    /** Active routings of this product's type, default first. */
    routings: { type: Array, default: () => [] },
    specs: { type: Array, default: () => [] },
    artworks: { type: Array, default: () => [] },
    boms: { type: Array, default: () => [] },
    currentSpecId: { type: Number, default: null },
    options: { type: Object, default: () => ({}) },
    designers: { type: Array, default: () => [] },
    suggestedArtworkCode: { type: String, default: '' },
});

/*
 * Gate 1 is noticed here — the readiness panel above says "approved artwork: missing" — so the
 * artwork is started here too, with the product already known. Saving lands on the new
 * artwork's own page, which is where version 1 is uploaded.
 */
const artworkOpen = ref(false);

const artworkForm = useForm({
    product_id: props.product.id,
    code: props.suggestedArtworkCode,
    title: props.product.name,
    designer_id: '',
});

function openArtwork() {
    artworkForm.clearErrors();
    artworkForm.reset();
    artworkOpen.value = true;
}

function createArtwork() {
    artworkForm.post('/artworks', { onSuccess: () => (artworkOpen.value = false) });
}

/*
 * The setup list. Each step arrives already judged by the server against what costing reads;
 * this only decides what the button beside it says and does. The first step that still needs
 * something is the one primary action on the page — the answer to "what do I do next?".
 */
const ACTIONS = {
    new_spec: { permission: 'product_spec.create', label: (step) => (step.state === 'attention' ? 'New version' : 'Add specification'), href: () => `/products/${props.product.id}/specs/create` },
    make_current: { permission: 'product_spec.make_current', label: () => 'Make current', run: (action) => makeCurrent(action) },
    new_artwork: { permission: 'artwork.create', label: () => 'Add artwork', run: () => openArtwork() },
    open_artwork: { permission: 'artwork.view_any', label: () => 'Open artwork', href: (action) => `/artworks/${action.id}` },
    new_bom: { permission: 'bom.create', label: (step) => (step.state === 'attention' ? 'New version' : 'Add bill of materials'), href: () => `/products/${props.product.id}/boms/create` },
    activate_bom: { permission: 'bom.activate', label: () => 'Activate', run: (action) => activateBom(action) },
    choose_routing: { permission: 'product.update', label: (step) => (step.state === 'done' ? 'Change' : 'Choose routing'), run: () => openRouting() },
    open_routing: { permission: 'routing.view_any', label: () => 'Open routing', href: (action) => `/routings/${action.id}` },
};

const steps = computed(() => {
    let primaryGiven = false;

    return props.setup.map((step, index) => {
        const spec = step.action ? ACTIONS[step.action.type] : null;
        const allowed = spec && can(spec.permission);
        const primary = allowed && step.state !== 'done' && !primaryGiven;

        primaryGiven ||= primary;

        return {
            ...step,
            no: index + 1,
            button: allowed
                ? {
                    label: spec.label(step),
                    href: spec.href ? spec.href(step.action) : null,
                    run: spec.run ? () => spec.run(step.action) : null,
                    variant: primary ? 'primary' : 'secondary',
                }
                : null,
        };
    });
});

const outstanding = computed(() => steps.value.filter((step) => step.state !== 'done'));

const setupSummary = computed(() => {
    if (outstanding.value.length === 0) {
        return 'Everything is in place. This product can be quoted, ordered and produced.';
    }

    const done = steps.value.length - outstanding.value.length;

    return `${done} of ${steps.value.length} done. Next: ${outstanding.value[0].label.toLowerCase()}.`;
});

/*
 * A finished setup is a status, not a to-do list. Five ticked rows took a third of the screen
 * to say "nothing to do here" and pushed the specification — what people open this page for —
 * below the fold. Once every step is done the list folds to one line of ticks beside the trial
 * price; the full steps are one click away, for the routing's "Change" and the price breakdown.
 */
const setupReady = computed(() => steps.value.length > 0 && outstanding.value.length === 0);
const stepsShown = ref(false);
const setupFolded = computed(() => setupReady.value && !stepsShown.value);
const priceStep = computed(() => steps.value.find((step) => step.key === 'price' && step.state === 'done') ?? null);
/** A price built on no material or no machine time is wrong, and has to say so even when folded. */
const priceSuspect = computed(() => priceStep.value && !(priceStep.value.parts.material > 0 && priceStep.value.parts.conversion > 0));

/** "v1 is current." under its own heading; "v1 is current" in a line of them. */
function brief(detail) {
    return String(detail ?? '').replace(/\.$/, '');
}

/* Routing is one field on the product, so it is chosen in the row rather than on the edit page. */
const routingOpen = ref(false);

const routingForm = useForm({ routing_id: props.product.routing?.id ?? '' });

function openRouting() {
    routingForm.clearErrors();
    routingForm.routing_id = props.product.routing?.id ?? props.routings.find((r) => r.is_default)?.id ?? '';
    routingOpen.value = true;
    stepsShown.value = true;
}

function saveRouting() {
    routingForm.put(`/products/${props.product.id}/routing`, {
        preserveScroll: true,
        onSuccess: () => (routingOpen.value = false),
    });
}

const { busy, run } = useGuardedAction();

/** "v3 " for the dialog title. The setup list passes only an id, so the version is looked up. */
function versionLabel(record, list) {
    const version = record.version_no ?? (list ?? []).find((row) => row.id === record.id)?.version_no;

    return version ? `v${version} ` : '';
}

function makeCurrent(spec) {
    run(`spec-${spec.id}`, {
        title: `Make specification ${versionLabel(spec, props.specs)}the current one?`,
        message: 'New quotations, orders and job cards will use this version. The version that is current now is kept as history and can no longer be used for new work.',
        confirmLabel: 'Make current',
    }, (done) => router.post(`/specs/${spec.id}/make-current`, {}, { preserveScroll: true, ...done }));
}

function activateBom(bom) {
    run(`bom-${bom.id}`, {
        title: `Activate bill of materials ${versionLabel(bom, props.boms).trim()}?`,
        message: 'Material planning and new job cards will draw from this version. The version that is active now is retired.',
        confirmLabel: 'Activate',
    }, (done) => router.post(`/boms/${bom.id}/activate`, {}, { preserveScroll: true, ...done }));
}

onMounted(() => {
    if (window.location.hash === '#bom') {
        document.getElementById('bom')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

const bomColumns = [
    { key: 'item', label: 'Material' },
    { key: 'qty_per_base', label: 'Qty / base', align: 'right' },
    { key: 'uom', label: 'Unit' },
    { key: 'wastage_pct', label: 'Wastage', align: 'right' },
    { key: 'colour_index', label: 'Colour', align: 'center' },
    { key: 'formula_ref', label: 'Quantity is' },
];
</script>

<template>
    <AppLayout>
        <Head :title="product.code" />

        <template #title>{{ product.code }} · {{ product.name }}</template>
        <template #subtitle>
            <Link v-if="product.customer" :href="`/customers/${product.customer.id}`" class="doc-link">
                {{ product.customer.name }}
            </Link>
            · {{ titleCase(product.product_type) }}
            <span v-if="product.customer_style_ref"> · style {{ product.customer_style_ref }}</span>
        </template>

        <template #actions>
            <Badge :status="product.status" />
            <Button v-if="can('product.update')" size="sm" :href="`/products/${product.id}/edit`">Edit</Button>
        </template>

        <div class="space-y-4">
            <!-- What this product still needs, in working order, and the one thing to do next. -->
            <!-- Done, the title says so and the ticks say the rest; a sentence beside two buttons had no room on a phone. -->
            <Card :title="setupReady ? 'Setup complete' : 'Setup'" :subtitle="setupReady ? null : setupSummary" :padded="false">
                <!-- Setup used to end on a trial price and nothing else. Once everything is in
                     place, the next thing anyone does with a product is quote it. -->
                <template v-if="setupReady" #actions>
                    <Button size="sm" variant="ghost" :aria-expanded="stepsShown" aria-controls="setup-steps" data-setup-toggle @click="stepsShown = !stepsShown">
                        {{ stepsShown ? 'Hide steps' : 'Show steps' }}
                    </Button>
                    <Button v-if="can('quotation.create')" size="sm" variant="primary" :href="`/quotations/create?product=${product.id}`">
                        Create a quotation
                    </Button>
                </template>

                <!-- Everything done: one line of ticks, and the figure they add up to. -->
                <div v-if="setupFolded" class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-2.5" data-setup-folded>
                    <ul class="flex min-w-0 flex-1 flex-wrap gap-x-5 gap-y-1 text-xs">
                        <li v-for="step in steps.filter((s) => s.key !== 'price')" :key="step.key" class="inline-flex items-baseline gap-1.5">
                            <Icon name="check" class="size-3.5 shrink-0 self-center text-emerald-600" />
                            <span class="font-medium text-ink-900">{{ step.label }}</span>
                            <span class="text-ink-600">{{ brief(step.detail) }}</span>
                            <span class="sr-only">— done</span>
                        </li>
                    </ul>
                    <p v-if="priceStep" class="text-xs text-ink-600 sm:text-right">
                        <span class="text-ink-500">{{ priceStep.label }}</span>
                        <span class="ml-1.5 text-sm font-semibold tnum text-ink-900">{{ ratePerM(priceStep.rate_per_m, priceStep.currency) }}</span>
                        <span class="ml-1.5">at {{ pcs(priceStep.qty) }} pcs, {{ pct(priceStep.margin_pct) }} margin</span>
                        <button v-if="priceSuspect" type="button" class="ml-1.5 inline-flex min-h-6 items-center gap-1 font-medium text-amber-900 underline underline-offset-2" @click="stepsShown = true">
                            <Icon name="warning" class="size-3.5" /> check the breakdown
                        </button>
                    </p>
                </div>

                <ol v-else id="setup-steps" class="divide-y divide-slate-100">
                    <li v-for="step in steps" :key="step.key" class="px-4" :class="step.state === 'done' ? 'py-2' : 'py-3'">
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                            <span
                                class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold tnum"
                                :class="{
                                    'bg-emerald-600 text-white': step.state === 'done',
                                    'bg-amber-100 text-amber-900 ring-1 ring-inset ring-amber-600/40': step.state === 'attention',
                                    'text-ink-600 ring-1 ring-inset ring-slate-300': step.state === 'todo',
                                }"
                                aria-hidden="true"
                            >
                                <Icon v-if="step.state === 'done'" name="check" class="size-3.5" />
                                <Icon v-else-if="step.state === 'attention'" name="warning" class="size-3.5" />
                                <template v-else>{{ step.no }}</template>
                            </span>

                            <div class="min-w-0 flex-1 basis-64" :class="step.state === 'done' ? 'flex flex-wrap items-baseline gap-x-2' : ''">
                                <p class="text-sm font-medium text-ink-900">
                                    {{ step.label }}
                                    <span class="sr-only">
                                        — {{ { done: 'done', attention: 'needs attention', todo: 'not done yet' }[step.state] }}
                                    </span>
                                </p>
                                <p class="mt-0.5 text-xs" :class="step.state === 'attention' ? 'text-amber-900' : 'text-ink-600'">
                                    {{ step.detail }}
                                </p>
                                <p v-if="step.state !== 'done' && step.unlocks" class="mt-0.5 text-xs text-ink-500">
                                    {{ step.unlocks }}
                                </p>
                            </div>

                            <!-- The trial price is the result the other four steps add up to. -->
                            <div v-if="step.key === 'price' && step.state === 'done'" class="max-sm:ml-10 sm:text-right">
                                <p class="text-sm font-semibold tnum text-ink-900">{{ ratePerM(step.rate_per_m, step.currency) }}</p>
                                <p class="text-xs text-ink-600">
                                    at {{ pcs(step.qty) }} pcs, {{ pct(step.margin_pct) }} margin{{ step.minimum_applied ? ', raised to the customer minimum' : '' }}
                                </p>
                                <p class="text-xs" :class="step.parts.material > 0 && step.parts.conversion > 0 ? 'text-ink-500' : 'text-amber-900'">
                                    material {{ money(step.parts.material, step.currency) }}, machine and labour {{ money(step.parts.conversion, step.currency) }}
                                </p>
                            </div>

                            <!-- Wrapped under the text on a narrow screen, it lines up with the text. -->
                            <div v-else-if="step.button && !(step.key === 'routing' && routingOpen)" class="max-sm:ml-10">
                                <Button v-if="step.button.href" size="sm" :variant="step.button.variant" :href="step.button.href">
                                    {{ step.button.label }}
                                </Button>
                                <Button v-else size="sm" :variant="step.button.variant" @click="step.button.run">
                                    {{ step.button.label }}
                                </Button>
                            </div>
                        </div>

                        <form
                            v-if="step.key === 'routing' && routingOpen"
                            class="mt-3 flex flex-wrap items-end gap-2 pl-10"
                            @submit.prevent="saveRouting"
                        >
                            <template v-if="routings.length">
                                <FormField
                                    class="min-w-64 flex-1 sm:max-w-md"
                                    :label="`Routing for a ${titleCase(product.product_type).toLowerCase()} product`"
                                    :error="routingForm.errors.routing_id"
                                >
                                    <SelectInput
                                        v-model="routingForm.routing_id"
                                        placeholder="— select —"
                                        :options="routings.map((r) => ({ id: r.id, label: `${r.code} · ${r.name}${r.is_default ? ' (default)' : ''}` }))"
                                        value-key="id"
                                        label-key="label"
                                    />
                                </FormField>
                                <Button type="submit" size="sm" variant="primary" :loading="routingForm.processing" :disabled="!routingForm.routing_id">
                                    Save routing
                                </Button>
                            </template>
                            <p v-else class="flex-1 text-xs text-ink-600">
                                There is no active routing for this product type yet.
                                <Link v-if="can('routing.create')" href="/routings/create" class="doc-link">Create one</Link>
                            </p>
                            <Button size="sm" variant="ghost" @click="routingOpen = false">Cancel</Button>
                        </form>
                    </li>
                </ol>
            </Card>

            <div class="grid gap-4 xl:grid-cols-3">
                <!-- Spec versions with their derived geometry -->
                <Card class="xl:col-span-2" title="Specifications" rule="P2 · P3" subtitle="A specification in use cannot be changed; a change is a new version" :padded="false">
                    <template #actions>
                        <Button v-if="can('product_spec.create')" size="sm" :href="`/products/${product.id}/specs/create`">
                            {{ specs.length ? 'New version' : 'New spec' }}
                        </Button>
                    </template>

                    <ul class="divide-y divide-slate-100">
                        <li v-for="spec in specs" :key="spec.id" class="p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold">v{{ spec.version_no }}</span>
                                        <Badge :status="spec.status" />
                                    </div>

                                    <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                                        <div><dt class="text-ink-500">Label</dt><dd class="tnum">{{ mm(spec.label_width_mm) }} × {{ mm(spec.label_height_mm) }}</dd></div>
                                        <div><dt class="text-ink-500">Web</dt><dd class="tnum">{{ spec.web_width_mm ? mm(spec.web_width_mm) : '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Cut</dt><dd>{{ titleCase(spec.cut_type) || '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Fold</dt><dd>{{ titleCase(spec.fold_type) || '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Colours</dt><dd class="tnum">{{ spec.colours }}</dd></div>
                                        <div><dt class="text-ink-500">Bundle</dt><dd class="tnum">{{ spec.bundle_size }} / {{ spec.bundles_per_carton }}</dd></div>
                                        <div><dt class="text-ink-500">GSM</dt><dd class="tnum">{{ spec.fabric_gsm != null ? number(spec.fabric_gsm) : '—' }}</dd></div>
                                        <div><dt class="text-ink-500">Coverage</dt><dd class="tnum">{{ pct(spec.coverage_pct) }}</dd></div>
                                    </dl>

                                    <!-- BR-4/5/6 shown beside the inputs that produced them -->
                                    <div class="mt-2 flex flex-wrap gap-3 rounded bg-slate-50 px-2 py-1.5 text-xs">
                                        <span><span class="text-ink-500">pitch</span> <span class="tnum font-medium">{{ number(spec.derived.pitch_mm) }} mm</span> <RuleHint rule="BR-4" size="size-3" /></span>
                                        <span><span class="text-ink-500">labels/m</span> <span class="tnum font-medium">{{ number(spec.derived.labels_per_metre, 1) }}</span> <RuleHint rule="BR-4" size="size-3" /></span>
                                        <span><span class="text-ink-500">ends</span> <span class="tnum font-medium">{{ spec.ends ?? spec.derived.suggested_ends }}</span> <RuleHint rule="BR-5" size="size-3" /></span>
                                        <span><span class="text-ink-500">labels/web m</span> <span class="tnum font-medium">{{ number(spec.derived.labels_per_web_metre, 1) }}</span> <RuleHint rule="BR-6" size="size-3" /></span>
                                    </div>
                                </div>

                                <Button
                                    v-if="spec.status !== 'current' && can('product_spec.make_current')"
                                    size="sm"
                                    :disabled="busy !== null"
                                    @click="makeCurrent(spec)"
                                >
                                    Make current
                                </Button>
                            </div>
                        </li>

                        <li v-if="specs.length === 0" class="p-6 text-center text-sm text-ink-500">
                            No spec yet. This product cannot be quoted or ordered until one exists —
                            start one with <strong>New spec</strong>.
                        </li>
                    </ul>
                </Card>

                <Card title="Artwork" rule="Gate 1" :padded="false">
                    <template #actions>
                        <Button v-if="can('artwork.create')" size="sm" @click="openArtwork">New artwork</Button>
                    </template>

                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="artwork in artworks" :key="artwork.id" class="p-3">
                            <Link :href="`/artworks/${artwork.id}`" class="doc-link-quiet">
                                {{ artwork.code }}
                            </Link>
                            <p class="text-xs text-ink-500">{{ artwork.title }}</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge
                                    v-for="version in artwork.versions"
                                    :key="version.id"
                                    :status="version.status"
                                    :label="`v${version.version_no}`"
                                />
                            </div>
                        </li>
                        <li v-if="artworks.length === 0" class="p-6 text-center text-ink-500">
                            No artwork yet. Production cannot be released against this product until one
                            version is approved.
                        </li>
                    </ul>
                </Card>
            </div>

            <Card id="bom" class="scroll-mt-20" title="Bills of material" subtitle="What this product consumes per 1,000 pieces. Activate one version before a job card can be released." :padded="false">
                <template #actions>
                    <Button v-if="can('bom.create')" size="sm" :href="`/products/${product.id}/boms/create`">
                        {{ boms.length ? 'New version' : 'New BOM' }}
                    </Button>
                </template>

                <div v-for="bom in boms" :key="bom.id" class="border-b border-slate-100 last:border-0">
                    <div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50/60 px-3 py-2">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold">v{{ bom.version_no }}</span>
                            <Badge :status="bom.status" />
                            <span class="text-xs text-ink-500">per {{ pcs(bom.base_qty) }} pcs</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <!-- A draft can be corrected; before, a typo meant a whole new version. -->
                            <Button v-if="bom.status === 'draft' && can('bom.update')" size="sm" :href="`/boms/${bom.id}/edit`" data-edit-bom>
                                Edit
                            </Button>
                            <Button v-if="bom.status !== 'active' && can('bom.activate')" size="sm" :disabled="busy !== null" @click="activateBom(bom)">
                                Activate
                            </Button>
                        </div>
                    </div>

                    <DataTable :columns="bomColumns" :rows="bom.lines" row-key="id" dense empty="No lines.">
                        <template #cell:item="{ row }">
                            <CodeName :code="row.item?.code" :name="row.item?.name" />
                        </template>
                        <template #cell:qty_per_base="{ value }">{{ qty(value) }}</template>
                        <template #cell:wastage_pct="{ value }">{{ pct(value) }}</template>
                        <template #cell:colour_index="{ value }">{{ value ?? 'all' }}</template>
                        <template #cell:formula_ref="{ value }">
                            <span v-if="value" class="inline-flex items-center gap-1.5">Worked out <RuleHint :rule="value" /></span>
                            <span v-else class="text-ink-600">Fixed</span>
                        </template>
                    </DataTable>
                </div>

                <p v-if="boms.length === 0" class="px-3 py-6 text-center text-sm text-ink-500">
                    No BOM yet. A job card cannot be released without an active one.
                </p>
            </Card>
        </div>
        <Modal
            v-model:open="artworkOpen"
            title="New artwork"
            :subtitle="`Filed against ${product.code}. Version 1 is uploaded on the artwork's own page.`"
        >
            <form class="space-y-3" @submit.prevent="createArtwork">
                <FormField label="Code" hint="Printed on the approval sheet the customer signs." :error="artworkForm.errors.code" required>
                    <TextInput v-model="artworkForm.code" />
                </FormField>

                <FormField label="Title" :error="artworkForm.errors.title" required>
                    <TextInput v-model="artworkForm.title" />
                </FormField>

                <FormField label="Designer" :error="artworkForm.errors.designer_id">
                    <SelectInput v-model="artworkForm.designer_id" :options="designers" value-key="id" label-key="name" />
                </FormField>
            </form>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="artworkForm.processing"
                    :disabled="!artworkForm.code || !artworkForm.title"
                    @click="createArtwork"
                >
                    Create artwork
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
