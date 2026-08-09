<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FilterBar from '@/Components/Ui/FilterBar.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { datetime } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
});

const editing = ref(null);
const selectedRoles = ref([]);

function open(user) {
    editing.value = user;
    selectedRoles.value = user.roles.map((r) => r.id);
}

function save() {
    router.post(`/admin/users/${editing.value.id}/roles`, { role_ids: selectedRoles.value }, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'roles', label: 'Roles' },
    { key: 'permission_count', label: 'Permissions', align: 'right' },
    { key: 'factory_unit', label: 'Unit' },
    { key: 'locale', label: 'Locale' },
    { key: 'last_login_at', label: 'Last login' },
    { key: 'actions', label: '' },
];
</script>

<template>
    <AppLayout>
        <Head title="Users & roles" />

        <template #title>Users &amp; roles</template>
        <template #subtitle>Permission-based, never role-based, at the code level — roles are editable bundles</template>

        <Card :padded="false">
            <FilterBar
                :filters="filters"
                :fields="[{ key: 'active', label: 'Status', options: [{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }] }]"
                placeholder="Search name or email…"
            />

            <DataTable :columns="columns" :rows="users" row-key="id" empty="No users." dense>
                <template #cell:name="{ row }">
                    <span class="font-medium text-slate-900">{{ row.name }}</span>
                    <Badge v-if="!row.is_active" tone="neutral" label="Inactive" class="ml-1" />
                </template>
                <template #cell:roles="{ value }">
                    <span class="flex flex-wrap gap-1">
                        <Badge v-for="role in value" :key="role.id" tone="info" :label="role.label" />
                    </span>
                </template>
                <template #cell:last_login_at="{ value }">{{ value ? datetime(value) : 'never' }}</template>
                <template #cell:actions="{ row }">
                    <Button size="sm" @click="open(row)">Roles</Button>
                </template>
            </DataTable>
        </Card>

        <Modal
            :open="editing !== null"
            :title="`Roles for ${editing?.name ?? ''}`"
            subtitle="Role changes are audit-logged with the old and new sets, and flush this user's permission cache."
            @update:open="editing = null"
        >
            <div class="grid max-h-96 gap-1.5 overflow-y-auto sm:grid-cols-2">
                <label
                    v-for="role in roles"
                    :key="role.id"
                    class="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50"
                >
                    <input v-model="selectedRoles" type="checkbox" :value="role.id" class="rounded border-slate-300">
                    <span>
                        <span class="font-medium text-slate-800">{{ role.label }}</span>
                        <span class="block font-mono text-[10px] text-slate-400">{{ role.name }}</span>
                    </span>
                </label>
            </div>

            <template #footer>
                <Button @click="editing = null">Cancel</Button>
                <Button variant="primary" @click="save">Save roles</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
