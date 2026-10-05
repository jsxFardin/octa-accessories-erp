<script setup>
import { router, usePage } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * An empty list that only says "Nothing here yet." tells a new user nothing about what the
 * record is for or how to make one. This says both, and carries the action.
 *
 * The `filtered` variant matters just as much: an empty list because of a filter looks
 * identical to an empty list because there is no data, and users read it as data loss.
 */
defineProps({
    icon: { type: String, default: 'inbox' },
    title: { type: String, required: true },
    description: { type: String, default: null },
    actionLabel: { type: String, default: null },
    actionHref: { type: String, default: null },
    /** True when a filter is hiding rows — the message and the way out are different. */
    filtered: { type: Boolean, default: false },
});

const emit = defineEmits(['action', 'clear-filters']);

const page = usePage();

/**
 * Clearing filters is the same act on every list: the same page with no query string. It is
 * done here once. Each page used to pass its own handler, written as
 * `router.get(window.location.pathname)` — and `window` does not exist inside a Vue template,
 * so on thirty-six lists the only button on this state threw instead of clearing.
 */
function clearFilters() {
    router.get(page.url.split('?')[0], {}, { preserveScroll: true });
    emit('clear-filters');
}
</script>

<template>
    <div class="mx-auto flex max-w-sm flex-col items-center py-4 text-center">
        <div class="mb-3 flex size-11 items-center justify-center rounded-full bg-slate-100">
            <Icon :name="filtered ? 'filter' : icon" size="size-5" class="text-ink-400" />
        </div>

        <p class="text-sm font-medium text-ink-900">
            {{ filtered ? 'Nothing matches these filters' : title }}
        </p>

        <p v-if="description || filtered" class="mt-1 text-xs leading-relaxed text-ink-500">
            {{ filtered ? 'Records exist, but none of them match. Clear the filters to see the full list.' : description }}
        </p>

        <div class="mt-3 flex items-center gap-2">
            <Button v-if="filtered" size="sm" @click="clearFilters">Clear filters</Button>

            <Button
                v-else-if="actionLabel"
                size="sm"
                variant="primary"
                :href="actionHref"
                @click="!actionHref && emit('action')"
            >
                <Icon name="add" size="size-3.5" />
                {{ actionLabel }}
            </Button>
        </div>
    </div>
</template>
