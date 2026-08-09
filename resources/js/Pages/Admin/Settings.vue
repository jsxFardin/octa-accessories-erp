<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import { titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ groups: { type: Object, default: () => ({}) } });

const form = useForm({
    settings: Object.values(props.groups).flat().map((s) => ({ key: s.key, value: s.value })),
});

function valueOf(key) {
    return form.settings.find((s) => s.key === key);
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
            <Button variant="primary" :loading="form.processing" @click="form.put('/admin/settings')">Save</Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card v-for="(settings, group) in groups" :key="group" :title="titleCase(group)">
                <div class="space-y-3">
                    <div v-for="setting in settings" :key="setting.key" class="grid grid-cols-3 items-start gap-3">
                        <div class="col-span-2">
                            <p class="font-mono text-xs text-ink-700">{{ setting.key }}</p>
                            <p class="text-xs text-ink-500">{{ setting.description }}</p>
                        </div>

                        <input
                            v-if="typeof setting.value === 'boolean'"
                            v-model="valueOf(setting.key).value"
                            type="checkbox"
                            class="mt-1 rounded border-slate-300"
                        >
                        <input
                            v-else
                            v-model="valueOf(setting.key).value"
                            class="form-input"
                            :class="typeof setting.value === 'number' ? 'text-right tnum' : ''"
                        >
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
