<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';

defineOptions({ layout: null });

/**
 * The organisation profile, shared with every page including this one. The sign-in screen used
 * to carry one customer's name in its markup, which a rebrand in settings could not change.
 */
const organisation = computed(() => usePage().props.app ?? {});
const productName = computed(() => organisation.value.short_name || 'Octa ERP');

/** Who to ask, from the organisation profile — whichever of phone and email is filled in. */
const contact = computed(() => [organisation.value.phone, organisation.value.email].filter(Boolean).join(' · '));

const showPassword = ref(false);
const helpOpen = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-screen items-center justify-center bg-slate-900 p-4">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <div
                    class="mx-auto mb-3 flex size-11 items-center justify-center overflow-hidden rounded-lg text-xl font-bold text-white"
                    :class="organisation.icon_url ? 'bg-white' : 'bg-brand-500'"
                >
                    <img v-if="organisation.icon_url" :src="organisation.icon_url" alt="" class="size-full object-contain">
                    <span v-else>{{ productName.charAt(0).toUpperCase() }}</span>
                </div>
                <h1 class="text-lg font-semibold text-white">{{ productName }}</h1>
                <p v-if="organisation.name" class="text-xs text-slate-300">{{ organisation.name }}</p>
            </div>

            <form class="space-y-4 rounded-lg bg-white p-6 shadow-xl" @submit.prevent="submit">
                <FormField label="Email" :error="form.errors.email" required>
                    <TextInput
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        autofocus
                        :error="form.errors.email"
                    />
                </FormField>

                <FormField label="Password" :error="form.errors.password" required>
                    <TextInput
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        :error="form.errors.password"
                    />
                </FormField>

                <div class="flex items-center justify-between gap-3 text-xs">
                    <label class="flex min-h-6 items-center gap-2 text-slate-600">
                        <input v-model="showPassword" type="checkbox" class="form-checkbox">
                        Show password
                    </label>

                    <button
                        type="button"
                        class="min-h-6 rounded text-brand-700 hover:underline focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        :aria-expanded="helpOpen"
                        aria-controls="password-help"
                        @click="helpOpen = !helpOpen"
                    >
                        Forgot your password?
                    </button>
                </div>

                <!--
                    There is no self-service reset: accounts are created by an administrator and
                    this installation sends no mail. What was missing was any sign of that — a
                    locked-out user had a form and nothing else.
                -->
                <p
                    v-if="helpOpen"
                    id="password-help"
                    class="rounded-md bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-700"
                >
                    Ask your system administrator to set a new password for you. They do it under
                    Configuration → Users, and you can change it yourself after signing in.
                    <span v-if="contact" class="mt-1 block font-medium text-slate-900">{{ contact }}</span>
                </p>

                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input v-model="form.remember" type="checkbox" class="form-checkbox">
                    Keep me signed in
                </label>

                <Button type="submit" variant="primary" class="w-full" :loading="form.processing">
                    Sign in
                </Button>
            </form>

            <p class="mt-4 text-center text-xs text-slate-300">
                Shop-floor operators sign in at the
                <a href="/floor" class="underline hover:text-white">terminal</a>
                with a badge scan.
            </p>
        </div>
    </div>
</template>
