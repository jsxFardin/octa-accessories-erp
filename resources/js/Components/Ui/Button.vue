<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    variant: { type: String, default: 'secondary' },
    size: { type: String, default: 'md' },
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    /**
     * Leaves the single-page application: a plain anchor rather than an Inertia visit. Print
     * views are Blade, and asking Inertia to fetch one gets an HTML document it cannot mount.
     */
    external: { type: Boolean, default: false },
    target: { type: String, default: null },
});

const VARIANTS = {
    primary: 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 focus-visible:outline-brand-600',
    secondary: 'bg-white text-ink-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-slate-400',
    danger: 'bg-rose-600 text-white shadow-sm hover:bg-rose-700 focus-visible:outline-rose-600',
    success: 'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700 focus-visible:outline-emerald-600',
    ghost: 'text-ink-700 hover:bg-slate-100 focus-visible:outline-slate-400',
};

const SIZES = {
    sm: 'px-2.5 py-1 text-xs gap-1',
    md: 'px-3 py-1.5 text-sm gap-1.5',
    lg: 'px-4 py-2 text-base gap-2',
};

const classes = computed(() => [
    'inline-flex items-center justify-center rounded-md font-medium transition',
    'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2',
    'disabled:cursor-not-allowed disabled:opacity-50',
    VARIANTS[props.variant] ?? VARIANTS.secondary,
    SIZES[props.size] ?? SIZES.md,
]);

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
    <a v-if="href && external && !isDisabled" :href="href" :target="target" :class="classes" rel="noopener">
        <slot />
    </a>
    <Link v-else-if="href && !isDisabled" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="isDisabled" :class="classes">
        <svg v-if="loading" class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        <slot />
    </button>
</template>
