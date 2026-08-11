<script setup>
import Icon from '@/Components/Ui/Icon.vue';

/**
 * The repeating-lines pattern every commercial document needs: inquiry lines, quotation lines,
 * order lines, GRN lines. One implementation, so numbering, the add and remove affordances and
 * the empty state behave identically across all of them.
 *
 * Two things changed after watching this next to a real invoice editor:
 *
 *  1. **The cells are borderless until you touch them.** A grid of outlined boxes reads as a
 *     wall of chrome, and on a six-column line table the borders drown the values that matter.
 *     Hover and focus bring the border back, which is when it is load-bearing.
 *  2. **Remove appears on row hover.** A column of permanent ✕ buttons invites the one action
 *     nobody opened the screen to do.
 *
 * The parent owns the array; this renders it and reports intent.
 */
defineProps({
    /** `[{ key, label, width?, align? }]` — the header row. */
    columns: { type: Array, required: true },
    lines: { type: Array, required: true },
    addLabel: { type: String, default: 'Add line' },
    empty: { type: String, default: 'No lines yet.' },
    /** Said above the empty state — what a line is for, not just that there are none. */
    emptyHint: { type: String, default: null },
    /** A document with produced quantity cannot lose its lines (S1). */
    canRemove: { type: Function, default: () => true },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['add', 'remove']);

/**
 * The server names the line in the message — "The line 3 quantity field is required" — because
 * that message is also read on its own, in a toast or a log. Inside the table the row number is
 * already in the first column, so the prefix is dropped here rather than sent twice.
 */
function cellError(index, key, errors) {
    const message = errors[`lines.${index}.${key}`];

    return message?.replace(/\b(line|operation|item|count|allocation|carton|row) \d+ /i, '');
}
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="w-8 pb-2 text-left text-[10px] font-semibold tracking-wider text-ink-400 uppercase">
                            #
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="px-1.5 pb-2 text-[10px] font-semibold tracking-wider text-ink-400 uppercase"
                            :class="column.align === 'right' ? 'text-right' : 'text-left'"
                            :style="column.width ? { width: column.width } : undefined"
                        >
                            {{ column.label }}
                        </th>
                        <th class="w-8 pb-2" />
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    <tr v-if="lines.length === 0">
                        <td :colspan="columns.length + 2" class="py-10 text-center">
                            <p class="text-sm text-ink-600">{{ empty }}</p>
                            <p v-if="emptyHint" class="mx-auto mt-1 max-w-sm text-xs leading-relaxed text-ink-500">
                                {{ emptyHint }}
                            </p>
                        </td>
                    </tr>

                    <tr v-for="(line, index) in lines" :key="index" class="group align-top">
                        <td class="py-1.5 text-xs tnum text-ink-400">{{ index + 1 }}</td>

                        <td v-for="column in columns" :key="column.key" class="px-1.5 py-1.5">
                            <slot :name="`cell:${column.key}`" :line="line" :index="index" />

                            <p v-if="cellError(index, column.key, errors)" class="mt-1 text-[11px] text-rose-600">
                                {{ cellError(index, column.key, errors) }}
                            </p>
                        </td>

                        <td class="py-1.5 text-right">
                            <button
                                v-if="canRemove(line, index)"
                                type="button"
                                class="rounded p-1 text-ink-300 opacity-0 transition group-hover:opacity-100 hover:bg-rose-50 hover:text-rose-600 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :aria-label="`Remove line ${index + 1}`"
                                title="Remove line"
                                @click="emit('remove', index)"
                            >
                                <Icon name="remove" size="size-3.5" />
                            </button>
                        </td>
                    </tr>
                </tbody>

                <tfoot v-if="$slots.footer" class="border-t-2 border-slate-200">
                    <slot name="footer" />
                </tfoot>
            </table>
        </div>

        <!-- A text link, not a button: adding a line is routine, not the page's main act. -->
        <div class="mt-3 flex items-center gap-4 text-sm">
            <button
                type="button"
                class="inline-flex items-center gap-1 text-brand-700 transition hover:underline focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                @click="emit('add')"
            >
                <Icon name="add" size="size-3.5" />
                {{ addLabel }}
            </button>

            <slot name="actions" />
        </div>
    </div>
</template>
