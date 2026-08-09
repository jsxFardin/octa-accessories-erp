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

/*
 * Display preferences come from the organisation profile (/admin/organisation) and are pushed
 * in once at boot. Defaults match the seeded profile so a component rendered before the first
 * page load — a test, a Storybook-style harness — still formats sensibly.
 */
const settings = {
    locale: 'en-GB',
    timezone: 'Asia/Dhaka',
    dateFormat: 'd M Y',
    timeFormat: 'HH:mm',
};

export function configureFormatting(values = {}) {
    if (values.number_locale) settings.locale = values.number_locale;
    if (values.timezone) settings.timezone = values.timezone;
    if (values.date_format) settings.dateFormat = values.date_format;
    if (values.time_format) settings.timeFormat = values.time_format;
}

export function formattingSettings() {
    return { ...settings };
}

/** PHP-style format tokens, because that is what the settings screen offers and stores. */
const DATE_PARTS = {
    d: { day: '2-digit' },
    j: { day: 'numeric' },
    m: { month: '2-digit' },
    n: { month: 'numeric' },
    M: { month: 'short' },
    F: { month: 'long' },
    Y: { year: 'numeric' },
    y: { year: '2-digit' },
};

function renderDate(value, format) {
    const parsed = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(parsed.getTime())) return '—';

    // Formatting part by part keeps the separators the admin chose ('/' vs '-' vs ' ').
    return [...format]
        .map((token) => {
            const options = DATE_PARTS[token];

            if (!options) return token;

            return parsed.toLocaleDateString(settings.locale, { ...options, timeZone: settings.timezone });
        })
        .join('');
}

function renderTime(value) {
    const parsed = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(parsed.getTime())) return '';

    return parsed.toLocaleTimeString(settings.locale, {
        timeZone: settings.timezone,
        hour: '2-digit',
        minute: '2-digit',
        hour12: settings.timeFormat !== 'HH:mm',
    });
}

function locale() {
    return settings.locale;
}

function toNumber(value) {
    const n = typeof value === 'string' ? Number.parseFloat(value) : value;

    return Number.isFinite(n) ? n : 0;
}

/** Piece counts: a label quantity is never 49,999.5 pieces. */
export function pcs(value) {
    return toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });
}

/** Metres and kilograms: fractional, because a roll is 1,847.325 m. */
export function qty(value, decimals = 3) {
    return toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function money(value, currency = null) {
    const formatted = toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return currency ? `${currency} ${formatted}` : formatted;
}

/** The per-1000 rate carries four decimals — the difference between 3.2500 and 3.2512 is
 *  real money at 500,000 pieces. */
export function ratePerM(value) {
    return toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: 4,
        maximumFractionDigits: 4,
    });
}

/**
 * Percentages carry the decimals they need and no more: a 5% tolerance reads `5%`, while a
 * 12.75% overhead keeps its digits. Column scale is a storage decision, not a display one.
 */
export function pct(value, decimals = null) {
    const number = toNumber(value);
    const places = decimals ?? (Number.isInteger(number) ? 0 : 2);

    return `${number.toFixed(places)}%`;
}

export function mm(value) {
    return `${toNumber(value).toFixed(2)} mm`;
}

/**
 * Timestamps are stored UTC and displayed in the factory's timezone (NFR-49). The conversion
 * happens here rather than in the database, so an export and a screen agree.
 */
export function datetime(value) {
    if (!value) return '—';

    const rendered = renderDate(value, settings.dateFormat);

    return rendered === '—' ? rendered : `${rendered} ${renderTime(value)}`;
}

export function date(value) {
    if (!value) return '—';

    return renderDate(value, settings.dateFormat);
}

export function time(value) {
    return value ? renderTime(value) : '—';
}

/** "3 days ago", "in 2 weeks" — for audit trails and activity rails, never for money. */
export function relative(value) {
    if (!value) return '—';

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) return '—';

    const seconds = Math.round((parsed.getTime() - Date.now()) / 1000);
    const units = [
        ['year', 31536000], ['month', 2592000], ['week', 604800],
        ['day', 86400], ['hour', 3600], ['minute', 60],
    ];

    const formatter = new Intl.RelativeTimeFormat(settings.locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(seconds, 'second');
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
            time,
            relative,
            documentNumber,
            titleCase,
        };
    },
};
