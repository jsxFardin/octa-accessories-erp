<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import Tabs from '@/Components/Ui/Tabs.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import { can } from '@/plugins/permissions';

/**
 * Settings, in two tabs.
 *
 * "Organisation" is who the company is and how the application renders dates and numbers;
 * "Business rules" is what the calculators are worth. They were separate screens, which asked
 * an administrator to know that the timezone lives in one place and the overhead percentage
 * in another — a distinction that matters to the code and to nobody else.
 */
const props = defineProps({
    tab: { type: String, default: 'organisation' },
    organisation: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    groups: { type: Object, default: () => ({}) },
});

const tabs = [
    { key: 'organisation', label: 'Organisation', href: '/admin/settings?tab=organisation' },
    { key: 'rules', label: 'Business rules', href: '/admin/settings?tab=rules' },
];

// --- Organisation tab --------------------------------------------------------------------
const profile = useForm({
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
    router.delete('/admin/organisation/branding', { data: { kind }, preserveScroll: true });
}

function saveProfile() {
    profile.put('/admin/organisation', { preserveScroll: true });
}

// --- Business rules tab ------------------------------------------------------------------
const allSettings = computed(() => Object.values(props.groups).flatMap((group) => group.settings));

const rules = useForm({
    settings: allSettings.value.map((setting) => ({ key: setting.key, value: setting.value })),
});

function entry(key) {
    return rules.settings.find((setting) => setting.key === key);
}

function saveRules() {
    rules.put('/admin/settings', { preserveScroll: true });
}

const activeForm = computed(() => (props.tab === 'rules' ? rules : profile));

function save() {
    props.tab === 'rules' ? saveRules() : saveProfile();
}
</script>

<template>
    <AppLayout>
        <Head title="Settings" />

        <template #title>Settings</template>
        <template #subtitle>
            {{ tab === 'rules'
                ? 'The coefficients of the business rules — the formulas themselves are code with tests'
                : 'Identity, branding and how dates and numbers are displayed' }}
        </template>

        <div class="space-y-4">
            <Tabs :tabs="tabs" :current="tab" />

            <!-- Organisation ------------------------------------------------------------->
            <div v-if="tab === 'organisation'" class="grid gap-4 xl:grid-cols-3">
                <div class="space-y-4 xl:col-span-2">
                    <Card title="Identity" subtitle="What this system calls itself, and what documents are printed under">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormField label="Trading name" hint="Shown in the sidebar." :error="profile.errors.org_name" required>
                                <TextInput v-model="profile.org_name" />
                            </FormField>

                            <FormField label="Product name" hint="Shown in the browser tab." :error="profile.errors.org_short_name" required>
                                <TextInput v-model="profile.org_short_name" />
                            </FormField>

                            <FormField
                                label="Legal name"
                                hint="Used on invoices and export documents."
                                :error="profile.errors.org_legal_name"
                                class="sm:col-span-2"
                            >
                                <TextInput v-model="profile.org_legal_name" />
                            </FormField>

                            <FormField label="Address" :error="profile.errors.org_address" class="sm:col-span-2">
                                <textarea v-model="profile.org_address" rows="2" class="form-textarea" />
                            </FormField>

                            <FormField label="Phone" :error="profile.errors.org_phone">
                                <TextInput v-model="profile.org_phone" />
                            </FormField>

                            <FormField label="Email" :error="profile.errors.org_email">
                                <TextInput v-model="profile.org_email" type="email" />
                            </FormField>

                            <FormField label="Website" :error="profile.errors.org_website">
                                <TextInput v-model="profile.org_website" placeholder="maheenlabel.com" />
                            </FormField>

                            <FormField label="BIN / VAT registration" :error="profile.errors.org_tax_id">
                                <TextInput v-model="profile.org_tax_id" />
                            </FormField>
                        </div>
                    </Card>

                    <Card title="Display" subtitle="Applies to every screen for every user">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <FormField
                                label="Timezone"
                                hint="Timestamps are stored UTC and rendered in this zone."
                                :error="profile.errors.timezone"
                                required
                            >
                                <SelectInput v-model="profile.timezone" :placeholder="null" :options="options.timezones" />
                            </FormField>

                            <FormField label="Date format" :error="profile.errors.date_format" required>
                                <SelectInput v-model="profile.date_format" :placeholder="null" :options="options.date_formats" />
                            </FormField>

                            <FormField label="Time format" :error="profile.errors.time_format" required>
                                <SelectInput v-model="profile.time_format" :placeholder="null" :options="options.time_formats" />
                            </FormField>

                            <FormField
                                label="Week starts on"
                                hint="The Bangladeshi working week runs Sunday to Thursday."
                                :error="profile.errors.week_start"
                                required
                            >
                                <SelectInput v-model="profile.week_start" :placeholder="null" :options="options.week_starts" />
                            </FormField>

                            <FormField label="Number format" hint="Thousands and decimal separators." :error="profile.errors.number_locale" required>
                                <SelectInput v-model="profile.number_locale" :placeholder="null" :options="options.number_locales" />
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

            <!-- Business rules ----------------------------------------------------------->
            <div v-else class="grid gap-4 lg:grid-cols-2">
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
                                <button
                                    v-if="typeof setting.value === 'boolean'"
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
                                        :class="typeof setting.value === 'number' && 'text-right tnum'"
                                        :type="typeof setting.value === 'number' ? 'number' : 'text'"
                                        :step="typeof setting.value === 'number' ? 'any' : undefined"
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
        </div>

        <FormFooter :form="activeForm" label="Save changes" @save="save" />
    </AppLayout>
</template>
