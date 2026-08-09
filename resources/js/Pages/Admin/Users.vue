<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DropdownMenu from '@/Components/Ui/DropdownMenu.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { datetime } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
});

const open = ref(false);
const editing = ref(null);

const BLANK = {
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    locale: 'en',
    is_active: true,
    role_id: '',
    employee_code: '',
    card_no: '',
    designation: '',
    factory_unit_id: '',
    department_id: '',
};

const form = useForm({ ...BLANK });

function openCreate() {
    editing.value = null;
    form.defaults({ ...BLANK });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function openEdit(user) {
    editing.value = user;
    form.defaults({
        ...BLANK,
        name: user.name,
        email: user.email,
        locale: user.locale,
        is_active: user.is_active,
        role_id: user.role_id ?? '',
        employee_code: user.employee?.code ?? '',
        card_no: user.employee?.card_no ?? '',
        designation: user.employee?.designation ?? '',
        factory_unit_id: user.employee?.factory_unit_id ?? '',
        department_id: user.employee?.department_id ?? '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    editing.value
        ? form.put(`/admin/users/${editing.value.id}`, options)
        : form.post('/admin/users', options);
}

function deactivate(user) {
    if (!confirm(`Deactivate ${user.name}? Their history is kept; they simply cannot sign in.`)) return;

    router.delete(`/admin/users/${user.id}`, { preserveScroll: true });
}

/** The row menu is built per row so it can hide what this admin may not do. */
function rowActions(user) {
    return [
        { label: 'Edit', hidden: !can('user.update'), onSelect: () => openEdit(user) },
        {
            label: 'Deactivate',
            tone: 'danger',
            hidden: !can('user.delete') || !user.is_active,
            onSelect: () => deactivate(user),
        },
    ];
}

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'role', label: 'Role' },
    { key: 'permission_count', label: 'Permissions', align: 'right' },
    { key: 'factory_unit', label: 'Unit' },
    { key: 'locale', label: 'Locale' },
    { key: 'last_login_at', label: 'Last sign-in' },
    { key: 'actions', label: '', align: 'right', width: '3rem' },
];
</script>

<template>
    <AppLayout>
        <Head title="Users" />

        <template #title>Users</template>
        <template #subtitle>
            Permission-based, never role-based, at the code level — roles are editable bundles.
        </template>

        <template #actions>
            <Button v-if="can('user.create')" variant="primary" @click="openCreate">New user</Button>
        </template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[
                    { key: 'active', label: 'Status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] },
                    { key: 'role', label: 'Role', options: roles.map((r) => ({ value: r.id, label: r.label })) },
                ]"
                placeholder="Search name or email…"
            />

            <DataTable :columns="columns" :rows="users" row-key="id" empty="No users match these filters." dense>
                <template #cell:name="{ row }">
                    <span class="font-medium text-ink-900">{{ row.name }}</span>
                    <Badge v-if="!row.is_active" tone="neutral" label="Inactive" class="ml-1" />
                </template>

                <template #cell:role="{ value }">
                    <Badge v-if="value" tone="info" :label="value.label" />
                    <span v-else class="text-xs text-ink-400">no role</span>
                </template>

                <template #cell:last_login_at="{ value }">{{ value ? datetime(value) : 'never' }}</template>

                <template #cell:actions="{ row }">
                    <DropdownMenu :items="rowActions(row)" :label="`Actions for ${row.name}`" />
                </template>
            </DataTable>
        </Card>

        <Modal
            v-model:open="open"
            :title="editing ? `Edit ${editing.name}` : 'New user'"
            subtitle="The role decides what they can open; the employee record decides which factory unit they are scoped to."
            width="max-w-3xl"
        >
            <div class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Name" :error="form.errors.name" required>
                        <TextInput v-model="form.name" />
                    </FormField>

                    <FormField label="Email" :error="form.errors.email" required>
                        <TextInput v-model="form.email" type="email" />
                    </FormField>

                    <FormField
                        label="Password"
                        :hint="editing ? 'Leave blank to keep the current password.' : 'At least 10 characters, letters and numbers.'"
                        :error="form.errors.password"
                        :required="!editing"
                    >
                        <TextInput v-model="form.password" type="password" autocomplete="new-password" />
                    </FormField>

                    <FormField label="Confirm password" :error="form.errors.password_confirmation">
                        <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                    </FormField>

                    <!--
                        One user, one role. Two roles would mean two answers to "what may this
                        person do", and the union of them is never what anyone intended.
                    -->
                    <FormField
                        label="Role"
                        hint="Manage what each role can do under Roles & permissions."
                        :error="form.errors.role_id"
                        required
                    >
                        <SelectInput
                            v-model="form.role_id"
                            placeholder="— select a role —"
                            :options="roles"
                            value-key="id"
                            label-key="label"
                        />
                    </FormField>

                    <FormField label="Language" hint="The shop floor runs in Bangla by default." :error="form.errors.locale">
                        <SelectInput
                            v-model="form.locale"
                            :placeholder="null"
                            :options="[{ value: 'en', label: 'English' }, { value: 'bn', label: 'বাংলা' }]"
                        />
                    </FormField>

                    <FormField label="Status">
                        <label class="flex items-center gap-2 py-1.5 text-sm text-ink-700">
                            <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            Active — may sign in
                        </label>
                    </FormField>
                </div>

                <div class="border-t border-slate-200 pt-3">
                    <p class="field-label">
                        Employee record
                        <span class="font-normal text-ink-500">— optional; needed for factory-unit scoping and badge login</span>
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <FormField label="Employee code" :error="form.errors.employee_code">
                            <TextInput v-model="form.employee_code" placeholder="EMP-0022" />
                        </FormField>

                        <FormField label="Badge number" hint="Signs in at the shop-floor terminal." :error="form.errors.card_no">
                            <TextInput v-model="form.card_no" placeholder="BADGE-0022" />
                        </FormField>

                        <FormField label="Designation" :error="form.errors.designation">
                            <TextInput v-model="form.designation" />
                        </FormField>

                        <FormField label="Factory unit" :error="form.errors.factory_unit_id">
                            <SelectInput v-model="form.factory_unit_id" :options="units" value-key="id" label-key="name" />
                        </FormField>

                        <FormField label="Department" :error="form.errors.department_id">
                            <SelectInput v-model="form.department_id" :options="departments" value-key="id" label-key="name" />
                        </FormField>
                    </div>
                </div>
            </div>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button variant="primary" :loading="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create user' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
