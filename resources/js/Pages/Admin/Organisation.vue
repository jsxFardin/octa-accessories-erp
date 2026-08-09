<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import UnsavedBar from '@/Components/Ui/UnsavedBar.vue';
import { can } from '@/plugins/permissions';

/**
 * The organisation profile — identity and display conventions in one place.
 *
 * Timezone is the setting that matters most: every timestamp is stored UTC and rendered here,
 * so getting it wrong shifts the whole system by hours without erroring once (NFR-49).
 */
const props = defineProps({
    organisation: { type: Object, required: true },
    options: { type: Object, required: true },
});

const form = useForm({
    org_name: props.organisation.org_name ?? '',
    org_legal_name: props.organisation.org_legal_name ?? '',
    org_short_name: props.organisation.org_short_name ?? '',
    org_address: props.organisation.org_address ?? '',
    org_phone: props.organisation.org_phone ?? '',
    org_email: props.organisation.org_email ?? '',
    org_website: props.organisation.org_website ?? '',
    org_tax_id: props.organisation.org_tax_id ?? '',
    timezone: props.organisation.timezone,
    date_format: props.organisation.date_format,
    time_format: props.organisation.time_format,
    week_start: props.organisation.week_start,
    number_locale: props.organisation.number_locale,
});

const uploading = ref(null);

/** Branding uploads post on their own so a rejected image never costs the typed form. */
function upload(kind, event) {
    const file = event.target.files?.[0];

    if (!file) return;

    uploading.value = kind;

    router.post('/admin/organisation/branding', { kind, file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploading.value = null;
            event.target.value = '';
        },
    });
}

function removeBranding(kind) {
    router.delete('/admin/organisation/branding', {
        data: { kind },
        preserveScroll: true,
    });
}

function save() {
    form.put('/admin/organisation', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Organisation" />

        <template #title>Organisation</template>
        <template #subtitle>Identity, branding and how dates and numbers are displayed</template>

        <template #actions>
            <Button
                v-if="can('setting.update')"
                variant="primary"
                :loading="form.processing"
                :disabled="!form.isDirty"
                @click="save"
            >
                Save changes
            </Button>
        </template>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                <Card title="Identity" subtitle="What this system calls itself, and what documents are printed under">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField
                            label="Trading name"
                            hint="Shown in the sidebar."
                            :error="form.errors.org_name"
                            required
                        >
                            <TextInput v-model="form.org_name" />
                        </FormField>

                        <FormField
                            label="Product name"
                            hint="Shown in the browser tab."
                            :error="form.errors.org_short_name"
                            required
                        >
                            <TextInput v-model="form.org_short_name" />
                        </FormField>

                        <FormField
                            label="Legal name"
                            hint="Used on invoices and export documents."
                            :error="form.errors.org_legal_name"
                            class="sm:col-span-2"
                        >
                            <TextInput v-model="form.org_legal_name" />
                        </FormField>

                        <FormField label="Address" :error="form.errors.org_address" class="sm:col-span-2">
                            <textarea v-model="form.org_address" rows="2" class="form-textarea" />
                        </FormField>

                        <FormField label="Phone" :error="form.errors.org_phone">
                            <TextInput v-model="form.org_phone" />
                        </FormField>

                        <FormField label="Email" :error="form.errors.org_email">
                            <TextInput v-model="form.org_email" type="email" />
                        </FormField>

                        <FormField label="Website" :error="form.errors.org_website">
                            <TextInput v-model="form.org_website" placeholder="maheenlabel.com" />
                        </FormField>

                        <FormField label="BIN / VAT registration" :error="form.errors.org_tax_id">
                            <TextInput v-model="form.org_tax_id" />
                        </FormField>
                    </div>
                </Card>

                <Card title="Display" subtitle="Applies to every screen for every user">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField
                            label="Timezone"
                            hint="Timestamps are stored UTC and rendered in this zone."
                            :error="form.errors.timezone"
                            required
                        >
                            <SelectInput
                                v-model="form.timezone"
                                :placeholder="null"
                                :options="options.timezones"
                            />
                        </FormField>

                        <FormField label="Date format" :error="form.errors.date_format" required>
                            <SelectInput v-model="form.date_format" :placeholder="null" :options="options.date_formats" />
                        </FormField>

                        <FormField label="Time format" :error="form.errors.time_format" required>
                            <SelectInput v-model="form.time_format" :placeholder="null" :options="options.time_formats" />
                        </FormField>

                        <FormField
                            label="Week starts on"
                            hint="The Bangladeshi working week runs Sunday to Thursday."
                            :error="form.errors.week_start"
                            required
                        >
                            <SelectInput v-model="form.week_start" :placeholder="null" :options="options.week_starts" />
                        </FormField>

                        <FormField
                            label="Number format"
                            hint="Thousands and decimal separators."
                            :error="form.errors.number_locale"
                            required
                        >
                            <SelectInput v-model="form.number_locale" :placeholder="null" :options="options.number_locales" />
                        </FormField>
                    </div>

                    <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-ink-600">
                        Display only. Quantities keep six decimals and money four in the database
                        regardless of what is shown here — rounding for the eye never reaches the ledger.
                    </p>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="Branding">
                    <div class="space-y-5">
                        <div>
                            <p class="field-label">Wordmark</p>
                            <div class="flex items-center gap-3">
                                <div class="flex h-14 w-32 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                                    <img v-if="organisation.logo_url" :src="organisation.logo_url" alt="" class="max-h-12 max-w-28 object-contain">
                                    <span v-else class="text-[10px] text-ink-400">none</span>
                                </div>

                                <div class="space-y-1">
                                    <input
                                        type="file"
                                        class="block w-full text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-xs"
                                        accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                        :disabled="uploading === 'logo'"
                                        @change="upload('logo', $event)"
                                    >
                                    <button
                                        v-if="organisation.logo_url"
                                        class="text-[11px] text-rose-600 hover:underline"
                                        @click="removeBranding('logo')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="field-label">Square mark</p>
                            <div class="flex items-center gap-3">
                                <div class="flex size-14 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                                    <img v-if="organisation.icon_url" :src="organisation.icon_url" alt="" class="max-h-12 max-w-12 object-contain">
                                    <span v-else class="text-[10px] text-ink-400">none</span>
                                </div>

                                <div class="space-y-1">
                                    <input
                                        type="file"
                                        class="block w-full text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-xs"
                                        accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                        :disabled="uploading === 'icon'"
                                        @change="upload('icon', $event)"
                                    >
                                    <button
                                        v-if="organisation.icon_url"
                                        class="text-[11px] text-rose-600 hover:underline"
                                        @click="removeBranding('icon')"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                            <p class="mt-1 text-[11px] text-ink-500">
                                Used as the favicon and the collapsed-sidebar badge. PNG, SVG or WebP, up to 1 MB.
                            </p>
                        </div>
                    </div>
                </Card>

                <Card title="Preview">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">Today</dt>
                            <dd class="tnum text-ink-900">{{ $fmt.date(new Date()) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">Now</dt>
                            <dd class="tnum text-ink-900">{{ $fmt.datetime(new Date()) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">Money</dt>
                            <dd class="tnum text-ink-900">{{ $fmt.money(1234567.891) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-500">Quantity</dt>
                            <dd class="tnum text-ink-900">{{ $fmt.qty(1847.325) }}</dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-[11px] text-ink-500">
                        The preview follows the saved profile, not the unsaved form.
                    </p>
                </Card>
            </div>
        </div>
        <UnsavedBar :form="form" @save="save" />

    </AppLayout>
</template>
