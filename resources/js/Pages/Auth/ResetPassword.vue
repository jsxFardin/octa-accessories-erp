<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';

defineOptions({ layout: null });

const props = defineProps({ token: { type: String, required: true }, email: { type: String, default: '' } });

const organisation = computed(() => usePage().props.app ?? {});
const productName = computed(() => organisation.value.short_name || 'Trimflow');

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>

<template>
    <Head title="Choose a new password" />

    <div class="flex min-h-screen items-center justify-center bg-slate-900 p-4">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <h1 class="text-lg font-semibold text-white">{{ productName }}</h1>
                <p class="text-xs text-slate-300">Choose a new password</p>
            </div>

            <form class="space-y-4 rounded-lg bg-white p-6 shadow-xl" @submit.prevent="form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })">
                <FormField label="Email" :error="form.errors.email" required>
                    <TextInput v-model="form.email" type="email" autocomplete="username" :error="form.errors.email" />
                </FormField>
                <FormField label="New password" :error="form.errors.password" hint="At least 10 characters, with letters and numbers." required>
                    <TextInput v-model="form.password" type="password" autocomplete="new-password" autofocus :error="form.errors.password" />
                </FormField>
                <FormField label="Confirm new password" :error="form.errors.password_confirmation" required>
                    <TextInput v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                </FormField>

                <Button type="submit" variant="primary" class="w-full" :loading="form.processing">Change password</Button>
            </form>
        </div>
    </div>
</template>
