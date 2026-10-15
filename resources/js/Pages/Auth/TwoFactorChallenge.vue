<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';

defineOptions({ layout: null });

const organisation = computed(() => usePage().props.app ?? {});
const productName = computed(() => organisation.value.short_name || 'Trimflow');

const form = useForm({ code: '' });
</script>

<template>
    <Head title="Two-step sign-in" />

    <div class="flex min-h-screen items-center justify-center bg-slate-900 p-4">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <h1 class="text-lg font-semibold text-white">{{ productName }}</h1>
                <p class="text-xs text-slate-300">Two-step sign-in</p>
            </div>

            <form class="space-y-4 rounded-lg bg-white p-6 shadow-xl" @submit.prevent="form.post('/two-factor-challenge', { onFinish: () => form.reset('code') })">
                <p class="text-sm text-slate-700">Open your authenticator app and enter the six-digit code it shows for this account.</p>

                <FormField label="Code" :error="form.errors.code" required>
                    <TextInput v-model="form.code" inputmode="numeric" autocomplete="one-time-code" autofocus maxlength="7" :error="form.errors.code" />
                </FormField>

                <Button type="submit" variant="primary" class="w-full" :loading="form.processing">Sign in</Button>

                <p class="text-center text-xs">
                    <Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link>
                </p>
            </form>
        </div>
    </div>
</template>
