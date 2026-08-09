<script setup>
import Button from '@/Components/Ui/Button.vue';

/**
 * The repeating-lines pattern every commercial document needs: inquiry lines, quotation
 * lines, order lines. One implementation so line numbering, the add/remove affordances and
 * the empty state behave identically across all three.
 *
 * The parent owns the array; this only renders it and reports intent.
 */
defineProps({
    /** `[{ key, label, width?, align? }]` — the header row. */
    columns: { type: Array, required: true },
    lines: { type: Array, required: true },
    addLabel: { type: String, default: 'Add line' },
    empty: { type: String, default: 'No lines yet.' },
    /** A document with produced quantity cannot lose its lines (S1). */
    canRemove: { type: Function, default: () => true },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['add', 'remove']);
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-200">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-10 px-2 py-2 text-center text-[10px] font-semibold tracking-wider text-ink-700 uppercase">
                            #
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="px-2 py-2 text-[10px] font-semibold tracking-wider text-ink-700 uppercase"
                            :class="column.align === 'right' ? 'text-right' : 'text-left'"
                            :style="column.width ? { width: column.width } : undefined"
                        >
                            {{ column.label }}
                        </th>
                        <th class="w-10 px-2 py-2" />
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-if="lines.length === 0">
                        <td :colspan="columns.length + 2" class="px-3 py-8 text-center text-sm text-ink-500">
                            {{ empty }}
                        </td>
                    </tr>

                    <tr v-for="(line, index) in lines" :key="index" class="align-top">
                        <td class="px-2 py-2 text-center text-xs tnum text-ink-500">{{ index + 1 }}</td>

                        <td v-for="column in columns" :key="column.key" class="px-2 py-2">
                            <slot :name="`cell:${column.key}`" :line="line" :index="index" />

                            <p v-if="errors[`lines.${index}.${column.key}`]" class="mt-1 text-[11px] text-rose-600">
                                {{ errors[`lines.${index}.${column.key}`] }}
                            </p>
                        </td>

                        <td class="px-2 py-2 text-right">
                            <button
                                v-if="canRemove(line, index)"
                                class="rounded p-1 text-ink-400 transition hover:bg-rose-50 hover:text-rose-600"
                                :aria-label="`Remove line ${index + 1}`"
                                title="Remove line"
                                @click="emit('remove', index)"
                            >
                                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round" />
                                </svg>
                            </button>
                        </td>
                    </tr>
                </tbody>

                <tfoot v-if="$slots.footer" class="border-t-2 border-slate-200 bg-slate-50">
                    <slot name="footer" />
                </tfoot>
            </table>
        </div>

        <div class="border-t border-slate-200 bg-slate-50/60 px-2 py-2">
            <Button size="sm" @click="emit('add')">+ {{ addLabel }}</Button>
        </div>
    </div>
</template>
