<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';

defineOptions({ layout: null });

const organisation = computed(() => usePage().props.app ?? {});
const productName = computed(() => organisation.value.short_name || 'Trimflow');
const sent = computed(() => usePage().props.flash?.success ?? null);

const form = useForm({ email: '' });
</script>

<template>
    <Head title="Reset your password" />

    <div class="flex min-h-screen items-center justify-center bg-slate-900 p-4">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <h1 class="text-lg font-semibold text-white">{{ productName }}</h1>
                <p class="text-xs text-slate-300">Reset your password</p>
            </div>

            <form class="space-y-4 rounded-lg bg-white p-6 shadow-xl" @submit.prevent="form.post('/forgot-password')">
                <p class="text-sm text-slate-700">
                    Enter the email on your account. A link to choose a new password is sent there and works for an hour.
                </p>

                <p v-if="sent" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">{{ sent }}</p>

                <FormField label="Email" :error="form.errors.email" required>
                    <TextInput v-model="form.email" type="email" autocomplete="username" autofocus :error="form.errors.email" />
                </FormField>

                <Button type="submit" variant="primary" class="w-full" :loading="form.processing">Send the link</Button>

                <p class="text-center text-xs">
                    <Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link>
                </p>
            </form>
        </div>
    </div>
</template>
