<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
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
const props = defineProps({ profile: { type: Object, required: true } });

// NFR-19 — two-step sign-in. Set up: the server mints a secret the authenticator enrols
// from; confirm: a code from the app proves the enrolment took; disable: the password.
const twoFactor = computed(() => props.profile.two_factor ?? {});
const setupForm = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ password: '' });

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

            <Card title="Two-step sign-in" class="lg:col-span-2" data-two-factor>
                <p v-if="usePage().props.flash?.warning" class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ usePage().props.flash.warning }}</p>

                <template v-if="twoFactor.enabled">
                    <p class="text-sm text-slate-700">On. Signing in asks for a code from your authenticator app after your password.</p>
                    <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="disableForm.delete('/profile/two-factor', { preserveScroll: true, onFinish: () => disableForm.reset() })">
                        <FormField label="Your password" :error="disableForm.errors.password" class="w-56">
                            <TextInput v-model="disableForm.password" type="password" autocomplete="current-password" />
                        </FormField>
                        <Button type="submit" variant="danger" size="sm" :loading="disableForm.processing" :disabled="!disableForm.password || twoFactor.required">Turn off</Button>
                        <p v-if="twoFactor.required" class="basis-full text-xs text-ink-500">Your role requires it; it cannot be turned off.</p>
                    </form>
                </template>

                <template v-else-if="twoFactor.pending_secret">
                    <p class="text-sm text-slate-700">
                        In your authenticator app (Google Authenticator, Microsoft Authenticator, Aegis…) add an account by entering this key, or open the link on your phone:
                    </p>
                    <p class="mt-2 font-mono text-lg tracking-widest text-ink-900" data-two-factor-secret>{{ twoFactor.pending_secret }}</p>
                    <p class="mt-1 break-all text-xs text-ink-500"><a :href="twoFactor.provisioning_uri" class="text-brand-700 hover:underline">{{ twoFactor.provisioning_uri }}</a></p>
                    <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="confirmForm.post('/profile/two-factor/confirm', { preserveScroll: true, onFinish: () => confirmForm.reset() })">
                        <FormField label="Code from the app" :error="confirmForm.errors.code" class="w-40">
                            <TextInput v-model="confirmForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" />
                        </FormField>
                        <Button type="submit" variant="primary" size="sm" :loading="confirmForm.processing" :disabled="!confirmForm.code">Confirm</Button>
                    </form>
                </template>

                <template v-else>
                    <p class="text-sm text-slate-700">
                        Off. With it on, signing in needs your password and a six-digit code from an authenticator app on your phone, so a password alone opens nothing.
                        <span v-if="twoFactor.required" class="font-medium text-amber-800">
                            Your role requires it.
                            <template v-if="twoFactor.days_left > 0">You have {{ twoFactor.days_left }} {{ twoFactor.days_left === 1 ? 'day' : 'days' }} left to set it up; after that only this page opens until you do.</template>
                            <template v-else-if="twoFactor.days_left === 0">Only this page opens until you set it up.</template>
                        </span>
                    </p>
                    <Button class="mt-3" variant="primary" size="sm" :loading="setupForm.processing" @click="setupForm.post('/profile/two-factor', { preserveScroll: true })">Set up</Button>
                </template>
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
