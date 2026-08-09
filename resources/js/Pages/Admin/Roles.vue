<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DropdownMenu from '@/Components/Ui/DropdownMenu.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    roles: { type: Array, default: () => [] },
    catalogue: { type: Array, default: () => [] },
    matrixActions: { type: Array, default: () => [] },
});

const ACTION_LABELS = {
    view_any: 'List',
    view: 'View',
    create: 'Create',
    update: 'Update',
    delete: 'Delete',
    export: 'Export',
};

const totalPermissions = computed(() =>
    props.catalogue.reduce((sum, row) => sum + Object.keys(row.actions).length + row.extras.length, 0),
);

const modules = computed(() => [...new Set(props.catalogue.map((row) => row.module))]);

// --- Editor ------------------------------------------------------------------------------
const open = ref(false);
const editing = ref(null);
const moduleFilter = ref('');
const search = ref('');

const form = useForm({ name: '', label: '', permissions: [] });

const visibleCatalogue = computed(() =>
    props.catalogue.filter((row) => {
        if (moduleFilter.value && row.module !== moduleFilter.value) return false;
        if (!search.value) return true;

        return row.label.toLowerCase().includes(search.value.toLowerCase());
    }),
);

function openCreate() {
    editing.value = null;
    form.defaults({ name: '', label: '', permissions: [] });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function openEdit(role) {
    editing.value = role;
    form.defaults({ name: role.name, label: role.label, permissions: [...role.permissions] });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function has(permission) {
    return form.permissions.includes(permission);
}

function toggle(permission) {
    form.permissions = has(permission)
        ? form.permissions.filter((p) => p !== permission)
        : [...form.permissions, permission];
}

/** Row "All" covers the standard actions and the exceptional ones alike. */
function rowPermissions(row) {
    return [...Object.values(row.actions), ...row.extras.map((extra) => extra.name)];
}

function rowFullySelected(row) {
    const all = rowPermissions(row);

    return all.length > 0 && all.every((permission) => has(permission));
}

function toggleRow(row) {
    const all = rowPermissions(row);

    form.permissions = rowFullySelected(row)
        ? form.permissions.filter((permission) => !all.includes(permission))
        : [...new Set([...form.permissions, ...all])];
}

/** Column header toggle: the same action across every visible row. */
function toggleColumn(action) {
    const names = visibleCatalogue.value.map((row) => row.actions[action]).filter(Boolean);
    const allOn = names.every((name) => has(name));

    form.permissions = allOn
        ? form.permissions.filter((permission) => !names.includes(permission))
        : [...new Set([...form.permissions, ...names])];
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    editing.value
        ? form.put(`/admin/roles/${editing.value.id}`, options)
        : form.post('/admin/roles', options);
}

function destroy(role) {
    if (!confirm(`Delete the role “${role.label}”? This cannot be undone.`)) return;

    router.delete(`/admin/roles/${role.id}`, { preserveScroll: true });
}

/** Built per card so it hides what this admin may not do. */
function cardActions(role) {
    return [
        {
            label: 'Edit',
            hidden: !can('role.update') || role.is_implicit_full_access,
            onSelect: () => openEdit(role),
        },
        {
            label: 'Delete',
            tone: 'danger',
            hidden: !can('role.delete') || role.is_system,
            onSelect: () => destroy(role),
        },
    ];
}

function accessPct(role) {
    if (role.is_implicit_full_access) return 100;

    return totalPermissions.value ? Math.round((role.permission_count / totalPermissions.value) * 100) : 0;
}
</script>

<template>
    <AppLayout>
        <Head title="Roles & permissions" />

        <template #title>Roles &amp; permissions</template>
        <template #subtitle>
            Roles are bundles of permissions, editable without a deploy. Code always asks for a
            permission, never a role name.
        </template>

        <template #actions>
            <Button v-if="can('role.create')" variant="primary" @click="openCreate">New role</Button>
        </template>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="role in roles"
                :key="role.id"
                class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                            ⛊
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink-900">{{ role.label }}</p>
                            <p class="truncate font-mono text-[10px] text-ink-400">{{ role.name }}</p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <Badge v-if="role.is_system" tone="neutral" label="System" />
                        <DropdownMenu :items="cardActions(role)" :label="`Actions for ${role.label}`" />
                    </div>
                </div>

                <div class="mt-3">
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="text-ink-500">Access level</span>
                        <span class="font-medium" :class="role.is_implicit_full_access ? 'text-brand-700' : 'text-ink-700'">
                            {{ role.is_implicit_full_access ? 'Full access' : `${role.permission_count} permissions` }}
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-brand-500" :style="{ width: `${accessPct(role)}%` }" />
                    </div>
                </div>

                <!--
                    super_admin holds no permission rows at all: the check short-circuits in
                    code, so the escape hatch cannot be revoked by editing a role.
                -->
                <p v-if="role.is_implicit_full_access" class="mt-3 text-xs text-ink-500">
                    Granted in code, not in data — this role cannot be locked out by editing it.
                </p>

                <div v-else class="mt-3">
                    <p class="mb-1 text-[10px] font-semibold tracking-wider text-ink-400 uppercase">Modules</p>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="module in role.modules.slice(0, 4)"
                            :key="module"
                            tone="neutral"
                            :label="module"
                        />
                        <Badge v-if="role.modules.length > 4" tone="neutral" :label="`+${role.modules.length - 4}`" />
                    </div>
                </div>

                <div class="mt-4 border-t border-slate-100 pt-3">
                    <span class="text-xs text-ink-500">{{ role.users_count }} user(s)</span>
                </div>
            </div>
        </div>

        <!-- Editor -->
        <Modal
            v-model:open="open"
            :title="editing ? `Edit ${editing.label}` : 'New role'"
            subtitle="Define what this role can view, create, update and delete across modules."
            width="max-w-4xl"
        >
            <div class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Role name" hint="Lower-case slug — this is the handle code and seeders use." :error="form.errors.name" required>
                        <TextInput v-model="form.name" :disabled="editing?.is_system" placeholder="shift_supervisor" />
                    </FormField>

                    <FormField label="Display label" :error="form.errors.label" required>
                        <TextInput v-model="form.label" placeholder="Shift supervisor" />
                    </FormField>
                </div>

                <div class="flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
                    <div class="min-w-40 flex-1">
                        <label class="field-label">Filter resources</label>
                        <TextInput v-model="search" type="search" placeholder="Search…" />
                    </div>
                    <div class="w-44">
                        <label class="field-label">Module</label>
                        <SelectInput
                            v-model="moduleFilter"
                            placeholder="All modules"
                            :options="modules.map((module) => ({ value: module, label: module }))"
                        />
                    </div>
                    <span class="pb-2 text-xs text-ink-500">
                        {{ form.permissions.length }} of {{ totalPermissions }} selected
                    </span>
                </div>

                <div class="max-h-96 overflow-y-auto rounded-md border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="sticky top-0 z-10 bg-slate-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-[10px] font-semibold tracking-wider text-ink-500 uppercase">
                                    Module
                                </th>
                                <th
                                    v-for="action in matrixActions"
                                    :key="action"
                                    class="px-2 py-2 text-center text-[10px] font-semibold tracking-wider text-ink-500 uppercase"
                                >
                                    <button class="hover:text-brand-600" :title="`Toggle ${action} for every visible row`" @click="toggleColumn(action)">
                                        {{ ACTION_LABELS[action] ?? titleCase(action) }}
                                    </button>
                                </th>
                                <th class="px-3 py-2 text-right text-[10px] font-semibold tracking-wider text-ink-500 uppercase">
                                    All
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100 bg-white">
                            <template v-for="row in visibleCatalogue" :key="row.resource">
                                <tr class="hover:bg-slate-50/60">
                                    <td class="px-3 py-2">
                                        <span class="font-medium text-ink-800">{{ row.label }}</span>
                                        <span class="ml-1.5 text-[10px] text-ink-400">{{ row.module }}</span>
                                    </td>

                                    <td v-for="action in matrixActions" :key="action" class="px-2 py-2 text-center">
                                        <input
                                            v-if="row.actions[action]"
                                            type="checkbox"
                                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                            :checked="has(row.actions[action])"
                                            @change="toggle(row.actions[action])"
                                        >
                                        <span v-else class="text-slate-200">—</span>
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        <button
                                            class="text-xs font-medium text-brand-600 hover:underline"
                                            @click="toggleRow(row)"
                                        >
                                            {{ rowFullySelected(row) ? 'None' : 'All' }}
                                        </button>
                                    </td>
                                </tr>

                                <!--
                                    The exceptional actions get their own line rather than
                                    hiding inside "All": granting `override_margin` or
                                    `waive_material` is meant to be a deliberate act.
                                -->
                                <tr v-if="row.extras.length" class="bg-amber-50/40">
                                    <td :colspan="matrixActions.length + 2" class="px-3 pb-2">
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                            <span class="text-[10px] font-semibold tracking-wider text-amber-700 uppercase">
                                                Exceptional
                                            </span>
                                            <label
                                                v-for="extra in row.extras"
                                                :key="extra.name"
                                                class="flex items-center gap-1.5 text-xs text-ink-700"
                                            >
                                                <input
                                                    type="checkbox"
                                                    class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                                                    :checked="has(extra.name)"
                                                    @change="toggle(extra.name)"
                                                >
                                                {{ extra.label }}
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="visibleCatalogue.length === 0">
                                <td :colspan="matrixActions.length + 2" class="px-3 py-8 text-center text-sm text-ink-500">
                                    No resource matches that filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="form.errors.permissions" class="text-xs text-rose-600">{{ form.errors.permissions }}</p>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create role' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
