<script setup>
/**
 * A job's actuals against its benchmark (spec §4): per unit by element, then line by line —
 * each material's usage variance told apart from its price variance, and each operation's
 * planned hours against the minutes it actually ran. Shared by the job card's Costing tab
 * and the post-production sheet so the two never disagree.
 */
import { computed } from 'vue';
import { money, number, pcs, pct, qty } from '@/plugins/formatting';

const props = defineProps({
    comparison: { type: Object, required: true },
    /** Show the marketing estimate beside the benchmark (the job card does; the sheet page does not). */
    marketing: { type: Boolean, default: false },
    currency: { type: String, default: undefined },
});

const floor = computed(() => props.comparison.margin_floor_pct ?? 12);
const lines = computed(() => props.comparison.line_variance ?? { scale: null, materials: [], conversion: [] });
const showMarketing = computed(() => props.marketing && !!props.comparison.marketing);
const varianceTone = (value) => (value === null || value === undefined ? '' : value > 0 ? 'text-rose-700' : value < 0 ? 'text-emerald-700' : '');
const belowFloor = (value) => value != null && value < floor.value;
const m = (value) => (value === null || value === undefined ? '—' : money(value, props.currency));
// Inside the line tables the currency is said once in the caption, so the figures fit the column.
const n = (value) => (value === null || value === undefined ? '—' : number(value, 2, 2));
const scaled = computed(() => lines.value.scale !== null && Math.abs(lines.value.scale - 1) > 0.000001);
</script>

<template>
    <div>
        <table class="min-w-full text-sm" data-testid="stage-elements">
            <thead class="bg-slate-50 text-xs text-ink-500">
                <tr>
                    <th class="px-3 py-2 text-left">Element</th>
                    <th v-if="showMarketing" class="px-3 py-2 text-right">Marketing</th>
                    <th class="px-3 py-2 text-right">Benchmark</th>
                    <th class="px-3 py-2 text-right">Actual</th>
                    <th class="px-3 py-2 text-right">Variance / unit</th>
                    <th class="px-3 py-2 text-right">Variance, job</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in comparison.variance" :key="row.key" class="border-t border-slate-100" :class="row.key === 'total_cost' && 'font-semibold'">
                    <td class="px-3 py-1.5">{{ row.label }}</td>
                    <td v-if="showMarketing" class="px-3 py-1.5 text-right tnum text-ink-500">{{ m(comparison.marketing.per_unit[row.key]) }}</td>
                    <td class="px-3 py-1.5 text-right tnum">{{ m(row.pre) }}</td>
                    <td class="px-3 py-1.5 text-right tnum">{{ m(row.post) }}</td>
                    <td class="px-3 py-1.5 text-right tnum" :class="varianceTone(row.per_unit)">{{ m(row.per_unit) }}</td>
                    <td class="px-3 py-1.5 text-right tnum" :class="varianceTone(row.total)">{{ m(row.total) }}</td>
                </tr>
                <tr class="border-t border-slate-200 bg-slate-50/60">
                    <td class="px-3 py-1.5">Price per unit</td>
                    <td v-if="showMarketing" class="px-3 py-1.5 text-right tnum text-ink-500">{{ m(comparison.marketing?.revenue_per_unit) }}</td>
                    <td class="px-3 py-1.5 text-right tnum">{{ m(comparison.pre?.revenue_per_unit) }}</td>
                    <td class="px-3 py-1.5 text-right tnum">{{ m(comparison.post?.revenue_per_unit) }}</td>
                    <td class="px-3 py-1.5" colspan="2"></td>
                </tr>
                <tr class="bg-slate-50/60 font-semibold">
                    <td class="px-3 py-1.5">Margin</td>
                    <td v-if="showMarketing" class="px-3 py-1.5 text-right tnum text-ink-500">{{ comparison.marketing?.margin_pct != null ? pct(comparison.marketing.margin_pct, 1) : '—' }}</td>
                    <td class="px-3 py-1.5 text-right tnum" :class="belowFloor(comparison.pre?.margin_pct) ? 'text-rose-700' : ''">{{ comparison.pre?.margin_pct != null ? pct(comparison.pre.margin_pct, 1) : '—' }}</td>
                    <td class="px-3 py-1.5 text-right tnum" :class="belowFloor(comparison.post?.margin_pct) ? 'text-rose-700' : ''">{{ comparison.post?.margin_pct != null ? pct(comparison.post.margin_pct, 1) : '—' }}</td>
                    <td class="px-3 py-1.5" colspan="2"></td>
                </tr>
            </tbody>
        </table>

        <template v-if="comparison.post && comparison.pre">
            <div class="border-t border-slate-200 px-3 pt-3 pb-1">
                <h3 class="text-sm font-semibold text-ink-900">Materials, line by line</h3>
                <p class="text-xs text-ink-600">
                    Planned → actual. Usage: more or less of it than planned, at the planned rate. Price: the planned quantity at a different rate. Amounts in {{ currency ?? 'the base currency' }}.
                    <span v-if="scaled">The benchmark is scaled to the {{ pcs(comparison.post.basis_qty) }} the job made.</span>
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm" data-testid="stage-materials">
                    <thead class="bg-slate-50 text-xs text-ink-500">
                        <tr>
                            <th class="px-2 py-2 text-left">Material</th>
                            <th class="px-2 py-2 text-right">Qty</th>
                            <th class="px-2 py-2 text-right">Rate</th>
                            <th class="px-2 py-2 text-right">Usage</th>
                            <th class="px-2 py-2 text-right">Price</th>
                            <th class="px-2 py-2 text-right">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!lines.materials.length"><td colspan="6" class="px-2 py-3 text-xs text-ink-500">No material on either sheet.</td></tr>
                        <tr v-for="row in lines.materials" :key="row.key" class="border-t border-slate-100 whitespace-nowrap">
                            <td class="min-w-36 px-2 py-1.5 whitespace-normal">
                                {{ row.description }}
                                <span v-if="!row.planned" class="ml-1 text-xs text-amber-700">not in the plan</span>
                                <span v-else-if="!row.actual" class="ml-1 text-xs text-ink-500">not issued</span>
                            </td>
                            <td class="px-2 py-1.5 text-right tnum">
                                <span class="text-ink-500">{{ row.planned ? qty(row.planned.qty) : '—' }}</span> →
                                <span :class="varianceTone(row.qty_variance)">{{ row.actual ? qty(row.actual.qty) : '—' }}</span>
                                <span class="text-xs text-ink-500">{{ row.uom ?? '' }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right tnum">
                                <span class="text-ink-500">{{ row.planned ? number(row.planned.rate, 2) : '—' }}</span> →
                                <span :class="varianceTone(row.rate_variance)">{{ row.actual ? number(row.actual.rate, 2) : '—' }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right tnum" :class="varianceTone(row.usage_variance)">{{ n(row.usage_variance) }}</td>
                            <td class="px-2 py-1.5 text-right tnum" :class="varianceTone(row.price_variance)">{{ n(row.price_variance) }}</td>
                            <td class="px-2 py-1.5 text-right tnum font-medium" :class="varianceTone(row.total_variance)">{{ n(row.total_variance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-3 pt-3 pb-1">
                <h3 class="text-sm font-semibold text-ink-900">Conversion, by operation</h3>
                <p class="text-xs text-ink-600">Planned → actual: hours from the routing against the minutes actually run, with machine, labour and energy together.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm" data-testid="stage-conversion">
                    <thead class="bg-slate-50 text-xs text-ink-500">
                        <tr>
                            <th class="px-2 py-2 text-left">Operation</th>
                            <th class="px-2 py-2 text-right">Hours</th>
                            <th class="px-2 py-2 text-right">Cost</th>
                            <th class="px-2 py-2 text-right">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!lines.conversion.length"><td colspan="4" class="px-2 py-3 text-xs text-ink-500">No operation on either sheet.</td></tr>
                        <tr v-for="row in lines.conversion" :key="row.key" class="border-t border-slate-100 whitespace-nowrap">
                            <td class="min-w-36 px-2 py-1.5 whitespace-normal">{{ row.operation }}</td>
                            <td class="px-2 py-1.5 text-right tnum">
                                <span class="text-ink-500">{{ row.planned ? number(row.planned.hours, 2) : '—' }}</span> →
                                <span :class="varianceTone(row.hours_variance)">{{ row.actual ? number(row.actual.hours, 2) : '—' }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right tnum">
                                <span class="text-ink-500">{{ row.planned ? n(row.planned.amount) : '—' }}</span> →
                                {{ row.actual ? n(row.actual.amount) : '—' }}
                            </td>
                            <td class="px-2 py-1.5 text-right tnum font-medium" :class="varianceTone(row.total_variance)">{{ n(row.total_variance) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
