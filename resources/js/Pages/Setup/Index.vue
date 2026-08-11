<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import DropdownMenu from '@/Components/Ui/DropdownMenu.vue';
import Icon from '@/Components/Ui/Icon.vue';
import SlideOver from '@/Components/Ui/SlideOver.vue';
import ReferenceFields from '@/Components/Ui/ReferenceFields.vue';
import Tabs from '@/Components/Ui/Tabs.vue';
import { titleCase } from '@/plugins/formatting';
import { useConfirm } from '@/composables/useConfirm';

/**
 * Setup — one tab per group, one card per list, each list edited where it is read.
 *
 * These are short lists that people scan and correct in place; sending someone to a separate
 * screen to add a department is a page load for a two-field form. The full screen is still a
 * click away for the lists that grow.
 */
const { confirm } = useConfirm();

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    current: { type: String, required: true },
    cards: { type: Array, default: () => [] },
});

const tabLinks = computed(() =>
    props.tabs.map((tab) => ({ ...tab, href: `/setup?tab=${tab.key}` })),
);

// --- Per-card search ---------------------------------------------------------------------
// Client-side: a card holds at most fifty rows, so a round trip per keystroke would be slower
// than the filter it is replacing.
const queries = ref({});

function visibleRows(card) {
    const needle = (queries.value[card.slug] ?? '').trim().toLowerCase();

    if (!needle) return card.rows;

    return card.rows.filter((row) =>
        Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(needle)),
    );
}

// --- Add / edit --------------------------------------------------------------------------
const open = ref(false);
const activeCard = ref(null);
const editing = ref(null);

const form = useForm({});

function blank(card) {
    return Object.fromEntries(
        card.fields.map((field) => [field.name, field.default ?? (field.type === 'boolean' ? false : '')]),
    );
}

function add(card) {
    activeCard.value = card;
    editing.value = null;
    form.defaults(blank(card));
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(card, row) {
    activeCard.value = card;
    editing.value = row;

    form.defaults(Object.fromEntries(
        card.fields.map((field) => [
            field.name,
            field.type === 'boolean' ? Boolean(row[field.name]) : (row[field.name] ?? ''),
        ]),
    ));
    form.reset();
    form.clearErrors();
    open.value = true;
}

function save() {
    const card = activeCard.value;
    const done = { preserveScroll: true, onSuccess: () => (open.value = false) };

    editing.value
        ? form.put(`/setup/${card.slug}/${editing.value.id}`, done)
        : form.post(`/setup/${card.slug}`, done);
}

async function remove(card, row) {
    const name = row.code ?? row.name ?? `#${row.id}`;

    if (!await confirm({
        title: `Delete ${name}?`,
        message: `This ${card.singular} is removed for good. Records already pointing at it will block the deletion.`,
        confirmLabel: 'Delete',
    })) return;

    router.delete(`/setup/${card.slug}/${row.id}`, { preserveScroll: true });
}

function rowActions(card, row) {
    return [
        { label: 'Edit', hidden: !card.can.update, onSelect: () => edit(card, row) },
        { label: 'Delete', tone: 'danger', hidden: !card.can.delete, onSelect: () => remove(card, row) },
    ];
}

/** Booleans read as a state, not as "true" — and only when the answer is interesting. */
function badgeFor(card, row, name) {
    const field = card.fields.find((candidate) => candidate.name === name);
    const value = row[name];

    if (field.type === 'boolean') {
        if (name.startsWith('is_active')) {
            return { tone: value ? 'success' : 'neutral', label: value ? 'Active' : 'Inactive' };
        }

        return value ? { tone: 'info', label: field.label } : null;
    }

    return value ? { tone: 'neutral', label: titleCase(String(value)) } : null;
}
</script>

<template>
    <AppLayout>
        <Head title="Setup" />

        <template #title>Setup</template>
        <template #subtitle>The lists behind every dropdown in the system</template>

        <div class="space-y-4">
            <Tabs :tabs="tabLinks" :current="current" />

            <div class="grid items-start gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                <section
                    v-for="card in cards"
                    :key="card.slug"
                    class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                >
                    <header class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-3 py-2.5">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-ink-900">
                            <Icon :name="card.icon" size="size-4" class="text-ink-400" />
                            {{ card.label }}
                            <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] tnum text-ink-600">
                                {{ card.total }}
                            </span>
                        </h2>

                        <div class="ml-auto flex items-center gap-2">
                            <div v-if="card.rows.length > 6" class="relative w-36">
                                <Icon name="search" size="size-3.5" class="pointer-events-none absolute top-2 left-2 text-ink-400" />
                                <input
                                    v-model="queries[card.slug]"
                                    type="search"
                                    class="form-input py-1 pl-7 text-xs"
                                    placeholder="Search"
                                >
                            </div>

                            <Button v-if="card.can.create" size="sm" variant="primary" @click="add(card)">
                                <Icon name="add" size="size-3.5" />
                                Add
                            </Button>
                        </div>
                    </header>

                    <div class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                        <div
                            v-for="row in visibleRows(card)"
                            :key="row.id"
                            class="flex items-center gap-2 px-3 py-2 transition hover:bg-slate-50"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-1.5 text-sm text-ink-900">
                                    <span class="font-medium">{{ row[card.display.title] ?? '—' }}</span>

                                    <template v-for="name in card.display.badges" :key="name">
                                        <Badge
                                            v-if="badgeFor(card, row, name)"
                                            :tone="badgeFor(card, row, name).tone"
                                            :label="badgeFor(card, row, name).label"
                                        />
                                    </template>
                                </p>
                                <p v-if="card.display.subtitle" class="truncate text-xs text-ink-500">
                                    {{ row[card.display.subtitle] }}
                                </p>
                            </div>

                            <DropdownMenu
                                v-if="card.can.update || card.can.delete"
                                :items="rowActions(card, row)"
                                :label="`Actions for ${row[card.display.title]}`"
                            />
                        </div>

                        <p v-if="card.rows.length === 0" class="px-3 py-8 text-center text-xs text-ink-500">
                            None yet.
                        </p>
                        <p
                            v-else-if="visibleRows(card).length === 0"
                            class="px-3 py-8 text-center text-xs text-ink-500"
                        >
                            Nothing matches “{{ queries[card.slug] }}”.
                        </p>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-100 bg-slate-50/70 px-3 py-1.5">
                        <p class="text-[11px] text-ink-500">
                            <span v-if="card.total > card.rows.length">
                                Showing {{ card.rows.length }} of {{ card.total }}
                            </span>
                        </p>
                        <Link
                            :href="`/setup/${card.slug}`"
                            class="text-[11px] text-brand-700 transition hover:underline"
                        >
                            Open full list
                        </Link>
                    </footer>
                </section>
            </div>

            <p v-if="cards.length === 0" class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-ink-500">
                You do not have access to any list in this group.
            </p>
        </div>

        <SlideOver
            v-if="activeCard"
            v-model:open="open"
            :title="editing ? `Edit ${activeCard.singular}` : `New ${activeCard.singular}`"
            :subtitle="activeCard.description"
            width="max-w-xl"
        >
            <ReferenceFields :fields="activeCard.fields" :form="form" :options="activeCard.options" />

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="form.processing" @click="save">
                    {{ editing ? 'Save changes' : `Add ${activeCard.singular}` }}
                </Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
