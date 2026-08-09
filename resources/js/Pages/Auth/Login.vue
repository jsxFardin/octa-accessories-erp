<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';

defineOptions({ layout: null });

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
                <div class="mx-auto mb-3 flex size-11 items-center justify-center rounded-lg bg-brand-500 text-xl font-bold text-white">
                    O
                </div>
                <h1 class="text-lg font-semibold text-white">Octa ERP</h1>
                <p class="text-xs text-slate-400">Maheen Label · Label &amp; garment-accessory manufacturing</p>
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
                        type="password"
                        autocomplete="current-password"
                        :error="form.errors.password"
                    />
                </FormField>

                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input v-model="form.remember" type="checkbox" class="rounded border-slate-300">
                    Keep me signed in
                </label>

                <Button type="submit" variant="primary" class="w-full" :loading="form.processing">
                    Sign in
                </Button>
            </form>

            <p class="mt-4 text-center text-[11px] text-slate-500">
                Shop-floor operators sign in at the terminal with a badge scan.
            </p>
        </div>
    </div>
</template>
