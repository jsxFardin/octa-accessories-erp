import { describe, expect, it } from 'vitest';
import { nextTick, reactive } from 'vue';
import { referenceRateOf, useBookedRate } from '../../resources/js/composables/useBookedRate.js';

const currencies = [
    { id: 1, code: 'BDT', is_base: true, reference_rate: 1 },
    { id: 2, code: 'USD', is_base: false, reference_rate: 122.5 },
    { id: 3, code: 'EUR', is_base: false, reference_rate: null },
];

describe('referenceRateOf', () => {
    it('is 1 for the base currency and the rate on file otherwise', () => {
        expect(referenceRateOf(currencies[0])).toBe(1);
        expect(referenceRateOf(currencies[1])).toBe(122.5);
    });

    it('is null when no rate is on file, never a silent 1', () => {
        expect(referenceRateOf(currencies[2])).toBeNull();
    });
});

describe('useBookedRate', () => {
    // UX audit H-07: the rate stayed at 1 whatever currency was chosen.
    it('fills the rate when the currency changes', async () => {
        const form = reactive({ currency_id: 1, exchange_rate: 1 });
        const { isBase } = useBookedRate(form, () => currencies);

        expect(isBase.value).toBe(true);

        form.currency_id = 2;
        await nextTick();

        expect(form.exchange_rate).toBe(122.5);
        expect(isBase.value).toBe(false);

        form.currency_id = 1;
        await nextTick();

        expect(form.exchange_rate).toBe(1);
    });

    it('starts a new foreign-currency document at the reference rate', () => {
        const form = reactive({ currency_id: 2, exchange_rate: 1 });

        useBookedRate(form, () => currencies);

        expect(form.exchange_rate).toBe(122.5);
    });

    it('leaves an existing document at the rate it was booked at', () => {
        const form = reactive({ currency_id: 2, exchange_rate: 119.75 });

        useBookedRate(form, () => currencies, { existing: true });

        expect(form.exchange_rate).toBe(119.75);
    });

    it('does not invent a rate for a currency with none on file, and says so', async () => {
        const form = reactive({ currency_id: 2, exchange_rate: 122.5 });
        const { rateHint } = useBookedRate(form, () => currencies, { existing: true });

        form.currency_id = 3;
        await nextTick();

        expect(form.exchange_rate).toBe(122.5);
        expect(rateHint.value).toMatch(/No exchange rate is on file for EUR/);
    });

    it('works with other field names', async () => {
        const form = reactive({ cost_currency: 1, cost_rate: 1 });

        useBookedRate(form, () => currencies, { currencyField: 'cost_currency', rateField: 'cost_rate' });
        form.cost_currency = 2;
        await nextTick();

        expect(form.cost_rate).toBe(122.5);
    });
});
