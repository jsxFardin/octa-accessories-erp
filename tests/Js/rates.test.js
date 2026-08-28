import { beforeEach, describe, expect, it } from 'vitest';
import {
    configureFormatting,
    ratePerM,
    unitCost,
} from '../../resources/js/plugins/formatting.js';

beforeEach(() => {
    configureFormatting({ number_locale: 'en-GB', base_currency: 'BDT' });
});

/**
 * F-04 — a rate is money, and money says which currency it is.
 *
 * The audit found these on screen with nothing to identify them:
 *
 *     Rate /M 270.2066
 *     Rate /M 17.4429
 *     Unit cost 0.216165
 *     100,000 pcs at 270.2066 /M
 *
 * `money()` had always labelled itself; `ratePerM()` returned a bare number, and most of this
 * factory's quotations are raised in USD while the cost sheet behind them is computed in BDT.
 * So the two numbers above were not merely unlabelled — read against the wrong currency they
 * were wrong by a factor of a hundred and twenty.
 */
describe('ratePerM carries its currency and its unit', () => {
    it('labels an unqualified rate with the factory currency', () => {
        expect(ratePerM(270.2066)).toBe('BDT 270.2066 /M');
    });

    it('labels a document rate with that document\'s currency', () => {
        // The reported case: a USD quotation whose rate read as a bare 270.2066.
        expect(ratePerM(270.2066, 'USD')).toBe('USD 270.2066 /M');
        expect(ratePerM(17.4429, { code: 'USD' })).toBe('USD 17.4429 /M');
    });

    it('keeps four decimals, because the fourth is real money at volume', () => {
        expect(ratePerM(3.25, 'BDT')).toBe('BDT 3.2500 /M');
        expect(ratePerM(3.2512, 'BDT')).toBe('BDT 3.2512 /M');
    });

    it('drops the currency but keeps the unit where a block states it once', () => {
        expect(ratePerM(270.2066, false)).toBe('270.2066 /M');
    });

    it('does not render a missing rate as a currency-labelled zero by accident', () => {
        expect(ratePerM(0, 'USD')).toBe('USD 0.0000 /M');
    });
});

describe('unitCost is recognisable as money', () => {
    it('labels a per-piece cost with the factory currency', () => {
        // `0.216165` on its own is not obviously money at all.
        expect(unitCost(0.216165)).toBe('BDT 0.216165');
    });

    it('keeps six decimals, because a label costs fractions of a taka', () => {
        expect(unitCost(0.2)).toBe('BDT 0.200000');
    });

    it('can be told the currency, or told to stay bare inside a labelled block', () => {
        expect(unitCost(0.216165, 'USD')).toBe('USD 0.216165');
        expect(unitCost(0.216165, false)).toBe('0.216165');
    });
});
