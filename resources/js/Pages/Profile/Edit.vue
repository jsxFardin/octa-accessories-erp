<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { datetime } from '@/plugins/formatting';

/*
 * There is no language choice here. The desk is in English only — it has no translations — so
 * a selector offering বাংলা changed nothing, and it sat inside this form, where saving it
 * also demanded a new password. The shop-floor terminal shows Bangla and English together and
 * needs no setting. If the desk is translated later, the choice belongs in its own card,
 * saved through `PUT /profile/locale`, which already exists and asks for no password.
 */
defineProps({ profile: { type: Object, required: true } });

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put('/profile/password', {
        preserveScroll: true,
        onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <AppLayout>
        <Head title="My account" />

        <template #title>My account</template>
        <template #subtitle>{{ profile.email }}</template>

        <div class="grid max-w-4xl gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Change password">
                <!-- Every seeded account starts on the same password; say so where it matters. -->
                <div
                    v-if="profile.using_seed_password"
                    class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                >
                    You are still using the password this account was created with. Everyone
                    seeded with the system shares it — change it before you rely on this account.
                </div>

                <form class="space-y-3" @submit.prevent="submit">
                    <FormField label="Current password" :error="form.errors.current_password" required>
                        <TextInput v-model="form.current_password" type="password" autocomplete="current-password" />
                    </FormField>

                    <FormField
                        label="New password"
                        hint="At least 10 characters, with letters and numbers, and not one that has appeared in a known breach."
                        :error="form.errors.password"
                        required
                    >
                        <TextInput v-model="form.password" type="password" autocomplete="new-password" />
                    </FormField>

                    <FormField label="Confirm new password" :error="form.errors.password_confirmation" required>
                        <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                    </FormField>

                    <Button type="submit" variant="primary" :loading="form.processing">Change password</Button>
                </form>
            </Card>

            <Card title="Account">
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-ink-500">Name</dt>
                        <dd class="font-medium text-ink-900">{{ profile.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Roles</dt>
                        <dd class="text-ink-800">{{ profile.roles.map((r) => r.label).join(', ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-500">Last sign-in</dt>
                        <dd class="text-ink-800">{{ profile.last_login_at ? datetime(profile.last_login_at) : 'never' }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs text-ink-500">
                    Roles are changed by an administrator, and every change is audit-logged.
                </p>
            </Card>
        </div>
    </AppLayout>
</template>
