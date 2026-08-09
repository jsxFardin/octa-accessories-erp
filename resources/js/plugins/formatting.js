/**
 * BR-47 — one place that decides how a number is displayed, so a quantity never appears with
 * two decimals on one screen and six on another.
 *
 * | Value              | Displayed                                     |
 * |--------------------|-----------------------------------------------|
 * | Quantity (pcs)     | thousands separated, no decimals              |
 * | Quantity (m, kg)   | 3 decimals                                    |
 * | Rate per M         | 4 decimals                                    |
 * | Line/document money| 2 decimals                                    |
 * | Percentage         | 2 decimals with %                             |
 */

const DISPLAY_LOCALE = 'en-GB';

function toNumber(value) {
    const n = typeof value === 'string' ? Number.parseFloat(value) : value;

    return Number.isFinite(n) ? n : 0;
}

/** Piece counts: a label quantity is never 49,999.5 pieces. */
export function pcs(value) {
    return toNumber(value).toLocaleString(DISPLAY_LOCALE, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
}

/** Metres and kilograms: fractional, because a roll is 1,847.325 m. */
export function qty(value, decimals = 3) {
    return toNumber(value).toLocaleString(DISPLAY_LOCALE, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function money(value, currency = null) {
    const formatted = toNumber(value).toLocaleString(DISPLAY_LOCALE, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return currency ? `${currency} ${formatted}` : formatted;
}

/** The per-1000 rate carries four decimals — the difference between 3.2500 and 3.2512 is
 *  real money at 500,000 pieces. */
export function ratePerM(value) {
    return toNumber(value).toLocaleString(DISPLAY_LOCALE, {
        minimumFractionDigits: 4,
        maximumFractionDigits: 4,
    });
}

export function pct(value, decimals = 2) {
    return `${toNumber(value).toFixed(decimals)}%`;
}

export function mm(value) {
    return `${toNumber(value).toFixed(2)} mm`;
}

/**
 * Timestamps are stored UTC and displayed in the factory's timezone (NFR-49). The conversion
 * happens here rather than in the database, so an export and a screen agree.
 */
export function datetime(value, timezone = 'Asia/Dhaka') {
    if (!value) return '—';

    return new Date(value).toLocaleString(DISPLAY_LOCALE, {
        timeZone: timezone,
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function date(value) {
    if (!value) return '—';

    return new Date(value).toLocaleDateString(DISPLAY_LOCALE, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    });
}

/** Documents in draft have no number yet, by design (BR-34). */
export function documentNumber(number, revisionNo = 0) {
    if (!number) return '(unnumbered)';

    return revisionNo > 0 ? `${number}/R${revisionNo}` : number;
}

export function titleCase(value) {
    if (!value) return '';

    return String(value)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default {
    install(app) {
        app.config.globalProperties.$fmt = {
            pcs,
            qty,
            money,
            ratePerM,
            pct,
            mm,
            datetime,
            date,
            documentNumber,
            titleCase,
        };
    },
};
