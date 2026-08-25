import { beforeEach, describe, expect, it } from 'vitest';
import {
    baseCurrency,
    configureFormatting,
    currencyCode,
    inBaseCurrency,
    money,
} from '../../resources/js/plugins/formatting.js';

beforeEach(() => {
    configureFormatting({ number_locale: 'en-GB', base_currency: 'BDT' });
});

describe('money always says which currency it is', () => {
    it('labels an unqualified amount with the factory currency', () => {
        // The regression this exists for: 3,630,453.60 sat beside 52.33 on the same screen
        // with nothing to say one was Taka and the other Dollars, and it read as bad data.
        expect(money(3630453.6)).toBe('BDT 3,630,453.60');
        expect(money(52.33)).toBe('BDT 52.33');
    });

    it('labels a document amount with that document\'s currency', () => {
        expect(money(52.33, 'USD')).toBe('USD 52.33');
        expect(money(52.33, { code: 'USD' })).toBe('USD 52.33');
    });

    it('never leaves two amounts in different currencies looking alike', () => {
        const bdt = money(3630453.6, { code: 'BDT' });
        const usd = money(52.33, { code: 'USD' });

        expect(bdt).toContain('BDT');
        expect(usd).toContain('USD');
        expect(bdt.replace(/[\d,.]/g, '').trim()).not.toBe(usd.replace(/[\d,.]/g, '').trim());
    });

    it('follows the organisation profile rather than hard-coding Taka', () => {
        configureFormatting({ base_currency: 'USD' });

        expect(baseCurrency()).toBe('USD');
        expect(money(10)).toBe('USD 10.00');
    });

    it('drops the label only where a block states the currency once', () => {
        expect(money(1234.5, false)).toBe('1,234.50');
    });

    it('still renders two decimals, thousands separated (BR-47)', () => {
        expect(money(1234.5)).toBe('BDT 1,234.50');
        expect(money('1234.567')).toBe('BDT 1,234.57');
        expect(money(null)).toBe('BDT 0.00');
    });
});

describe('currency code resolution', () => {
    it('reads a code out of a string, an object, or nothing', () => {
        expect(currencyCode('USD')).toBe('USD');
        expect(currencyCode({ code: 'EUR', symbol: '€' })).toBe('EUR');
        expect(currencyCode({ currency_code: 'GBP' })).toBe('GBP');
        expect(currencyCode(null)).toBeNull();
        expect(currencyCode(undefined)).toBeNull();
    });
});

describe('the conversion behind a foreign-currency document', () => {
    it('states what a USD document is worth in the books', () => {
        // A cost sheet is computed in Taka and the document is quoted in Dollars; hiding the
        // relationship is what made the two numbers look unrelated.
        expect(inBaseCurrency(100, 'USD', 122.5)).toBe('BDT 12,250.00');
    });

    it('says nothing when there is nothing to convert', () => {
        expect(inBaseCurrency(100, 'BDT', 1)).toBeNull();
        expect(inBaseCurrency(100, 'USD', 1)).toBeNull();
        expect(inBaseCurrency(100, null, 122.5)).toBeNull();
    });
});
