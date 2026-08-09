<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The shop-floor layout. Nothing here is shared with AppLayout on purpose: gloves, glare and
 * a four-button vocabulary are a different design language from a dense desk grid
 * (08-architecture §4).
 */
const page = usePage();
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div class="floor-scope min-h-screen">
        <header class="flex items-center justify-between border-b border-white/10 px-6 py-4">
            <div>
                <h1 class="text-2xl font-bold"><slot name="title" /></h1>
                <p class="text-sm text-slate-400"><slot name="subtitle" /></p>
            </div>
            <slot name="actions" />
        </header>

        <div
            v-if="flash.error"
            class="mx-6 mt-4 rounded-xl bg-rose-600 px-5 py-4 text-lg font-semibold whitespace-pre-line"
        >
            {{ flash.error }}
        </div>
        <div v-if="flash.success" class="mx-6 mt-4 rounded-xl bg-emerald-600 px-5 py-4 text-lg font-semibold">
            {{ flash.success }}
        </div>

        <main class="p-6">
            <slot />
        </main>
    </div>
</template>
