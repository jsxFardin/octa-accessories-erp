import { computed, watch } from 'vue';

/**
 * The exchange rate on a document form, kept in step with its currency.
 *
 * The rate field defaulted to 1 and was left for the user to correct. For any foreign-currency
 * document that meant wrong figures on screen while typing, and a refusal on save from a
 * server that knows the reference rate perfectly well. Each currency now arrives with that
 * rate, and this keeps the form's rate equal to it:
 *
 *  - choosing a currency (or a customer or supplier that brings one) fills the rate;
 *  - the base currency is always 1, and its field can be hidden with `isBase`;
 *  - a rate the user then types is left alone until the currency changes again;
 *  - an existing document keeps the rate it was booked at when the form opens.
 *
 * @param {object} form        an Inertia `useForm` with the two fields below
 * @param {() => Array} currencies  `[{ id, code, is_base, reference_rate }]`
 * @param {{ currencyField?: string, rateField?: string, existing?: boolean }} options
 */
export function useBookedRate(form, currencies, options = {}) {
    const currencyField = options.currencyField ?? 'currency_id';
    const rateField = options.rateField ?? 'exchange_rate';

    const currency = computed(
        () => currencies().find((row) => String(row.id) === String(form[currencyField])) ?? null,
    );
    const base = computed(() => currencies().find((row) => row.is_base) ?? null);

    /** True while the document is in the factory's own currency — there is no rate to enter. */
    const isBase = computed(() => !currency.value || Boolean(currency.value.is_base));

    const referenceRate = computed(() => referenceRateOf(currency.value));

    /** "1 USD = 122.50 BDT on file", for the hint under the field. */
    const rateHint = computed(() => {
        if (isBase.value || !currency.value) return null;

        return referenceRate.value === null
            ? `No exchange rate is on file for ${currency.value.code}. Add one under Configuration → Lists before saving.`
            : `Rate on file: 1 ${currency.value.code} = ${referenceRate.value} ${base.value?.code ?? ''}. Change it only if this document was agreed at a different rate.`;
    });

    function apply() {
        const rate = referenceRate.value;

        if (rate !== null) form[rateField] = rate;
    }

    watch(() => form[currencyField], apply);

    // A new document starts at the right rate; an existing one keeps what it was booked at.
    if (!options.existing) apply();

    return { isBase, referenceRate, rateHint, currency };
}

/** The rate a document in this currency is booked at by default; 1 for base, null if unknown. */
export function referenceRateOf(currency) {
    if (!currency || currency.is_base) return 1;

    const rate = Number(currency.reference_rate);

    return Number.isFinite(rate) && rate > 0 ? rate : null;
}
