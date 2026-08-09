<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import UnsavedBar from '@/Components/Ui/UnsavedBar.vue';
import { can } from '@/plugins/permissions';

const props = defineProps({ groups: { type: Object, default: () => ({}) } });

const allSettings = computed(() => Object.values(props.groups).flatMap((group) => group.settings));

const form = useForm({
    settings: allSettings.value.map((setting) => ({ key: setting.key, value: setting.value })),
});

function entry(key) {
    return form.settings.find((setting) => setting.key === key);
}

function isBoolean(setting) {
    return typeof setting.value === 'boolean';
}

function isNumeric(setting) {
    return typeof setting.value === 'number';
}

function save() {
    form.put('/admin/settings', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Settings" />

        <template #title>Settings</template>
        <template #subtitle>
            The coefficients of the business rules. The formulas themselves are code with tests —
            a rule that must not change quietly does not live here.
        </template>

        <template #actions>
            <Button
                v-if="can('setting.update')"
                variant="primary"
                :loading="form.processing"
                :disabled="!form.isDirty"
                @click="save"
            >
                Save
            </Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card v-for="(group, name) in groups" :key="name" :title="group.label" :padded="false">
                <div class="divide-y divide-slate-100">
                    <div
                        v-for="setting in group.settings"
                        :key="setting.key"
                        class="grid grid-cols-5 items-start gap-3 px-4 py-3"
                    >
                        <div class="col-span-3 min-w-0">
                            <label class="block text-sm font-medium text-ink-900" :for="`setting-${setting.key}`">
                                {{ setting.label }}
                            </label>
                            <p v-if="setting.hint" class="mt-0.5 text-xs leading-relaxed text-ink-500">
                                {{ setting.hint }}
                            </p>
                            <!-- The key stays visible: it is what appears in the audit log and in support threads. -->
                            <p class="mt-1 font-mono text-[10px] text-ink-400">{{ setting.key }}</p>
                        </div>

                        <div class="col-span-2 flex items-center justify-end gap-2">
                            <!-- A boolean is a switch, not a bare checkbox floating in a column. -->
                            <button
                                v-if="isBoolean(setting)"
                                :id="`setting-${setting.key}`"
                                type="button"
                                role="switch"
                                :aria-checked="entry(setting.key).value"
                                class="relative h-5 w-9 shrink-0 rounded-full transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :class="entry(setting.key).value ? 'bg-brand-600' : 'bg-slate-300'"
                                @click="entry(setting.key).value = !entry(setting.key).value"
                            >
                                <span
                                    class="absolute top-0.5 size-4 rounded-full bg-white shadow transition-all"
                                    :class="entry(setting.key).value ? 'left-4.5' : 'left-0.5'"
                                />
                            </button>

                            <template v-else>
                                <input
                                    :id="`setting-${setting.key}`"
                                    v-model="entry(setting.key).value"
                                    class="form-input"
                                    :class="isNumeric(setting) && 'text-right tnum'"
                                    :type="isNumeric(setting) ? 'number' : 'text'"
                                    :step="isNumeric(setting) ? 'any' : undefined"
                                >
                                <span v-if="setting.unit" class="w-16 shrink-0 text-xs text-ink-500">
                                    {{ setting.unit }}
                                </span>
                                <span v-else class="w-16 shrink-0" />
                            </template>
                        </div>
                    </div>
                </div>
            </Card>
        </div>

        <UnsavedBar :form="form" label="Save settings" @save="save" />
    </AppLayout>
</template>
