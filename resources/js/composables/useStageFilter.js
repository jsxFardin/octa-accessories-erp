import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { date, isoDate, pcs, titleCase, todayIso } from '@/plugins/formatting';

/**
 * The strip of counts above a list, and what clicking a tile does.
 *
 *     const { stages, select } = useStageFilter('/purchase-orders', () => props.filters, () => props.counts, {
 *         statuses: ['draft', 'sent', …],
 *         alert: { key: 'late', label: 'Late' },          // optional: a count that is a problem
 *         labels: { pending_qc: 'Awaiting QC' },           // optional: where titleCase reads badly
 *         warn: ['pending_approval'],                      // optional: amber while not zero
 *     });
 *
 * A tile is a toggle: clicking the stage already shown clears it. The alert and a status are
 * one choice, not two — "late" with "draft" is always empty.
 */
export function useStageFilter(url, filters, counts, { statuses, alert = null, labels = {}, warn = [], param = 'status' }) {
    const stages = computed(() => {
        const current = filters() ?? {};
        const totals = counts() ?? {};
        const alertOn = alert && current[alert.key] === '1';

        return [
            ...(alert ? [{ key: alert.key, label: alert.label, count: totals[alert.key] ?? 0, active: alertOn, tone: 'danger' }] : []),
            ...statuses.map((status) => ({
                key: status,
                label: labels[status] ?? titleCase(status),
                count: totals[status] ?? 0,
                active: String(current[param] ?? '') === String(status) && !alertOn,
                tone: warn.includes(status) ? 'warning' : 'neutral',
            })),
        ];
    });

    function select(key) {
        const current = filters() ?? {};
        const next = { ...current, page: undefined };

        if (alert && key === alert.key) {
            next[alert.key] = current[alert.key] === '1' ? '' : '1';
            next[param] = '';
        } else {
            next[param] = String(current[param] ?? '') === String(key) ? '' : key;
            if (alert) next[alert.key] = '';
        }

        router.get(url, Object.fromEntries(Object.entries(next).filter(([, value]) => value !== '' && value !== null && value !== undefined)), {
            preserveState: true,
            preserveScroll: true,
        });
    }

    return { stages, select };
}

const days = (n) => `${pcs(Math.abs(n))} ${Math.abs(n) === 1 ? 'day' : 'days'}`;

/** Whole calendar days from today to a date, negative when it has passed; null without a date. */
export function daysFromToday(value) {
    const iso = isoDate(value);

    if (!iso) return null;

    const [y, m, d] = iso.split('-').map(Number);
    const [ty, tm, td] = todayIso().split('-').map(Number);

    return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
}

/**
 * A date as a deadline: how late, how soon, or just when.
 *
 * `live` is whether the deadline still matters — a received order is not "12 days overdue".
 *
 * @returns {{ text: string, tone: string }}
 */
export function deadline(value, live = true, { soon = 7, late = 'overdue' } = {}) {
    const diff = daysFromToday(value);

    if (diff === null) return { text: '—', tone: 'text-ink-400' };
    if (!live) return { text: date(value), tone: '' };
    if (diff < 0) return { text: `${date(value)} · ${days(diff)} ${late}`, tone: 'font-medium text-rose-700' };
    if (diff <= soon) return { text: `${date(value)} · ${diff === 0 ? 'today' : `in ${days(diff)}`}`, tone: 'text-amber-700' };

    return { text: date(value), tone: '' };
}
