/**
 * BR-47 — one place that decides how a number is displayed, so a quantity never appears with
 * two decimals on one screen and six on another.
 *
 * | Value              | Displayed                                     |
 * |--------------------|-----------------------------------------------|
 * | Quantity (pcs)     | thousands separated, no decimals              |
 * | Quantity (m, kg)   | the organisation's decimals (2 by default)    |
 * | Rate per M         | the organisation's decimals (2 by default)    |
 * | Line/document money| 2 decimals                                    |
 * | Percentage         | 2 decimals with %                             |
 */

/*
 * Display preferences come from the organisation profile (/admin/organisation) and are pushed
 * in once at boot. Defaults match the seeded profile so a component rendered before the first
 * page load — a test, a Storybook-style harness — still formats sensibly.
 */
const settings = {
    /*
     * Lakh and crore grouping (12,34,567) with ordinary digits — how amounts are written in
     * the factory's own books — unless the organisation profile says otherwise.
     */
    locale: 'en-IN',
    timezone: 'Asia/Dhaka',
    dateFormat: 'd M Y',
    timeFormat: 'HH:mm',
    /*
     * Every amount says which currency it is. A quotation in USD sits in the same list as one
     * in BDT, and `3,630,453.60` beside `52.33` reads as corrupted data rather than as two
     * currencies — which is exactly how it was reported. Documents that carry their own
     * currency pass it; everything else falls back to the factory's base currency, so a bare
     * `money(x)` is never ambiguous.
     */
    baseCurrency: 'BDT',
    /** First column of every calendar: 'saturday', 'sunday' or 'monday'. */
    weekStart: 'saturday',
    /**
     * Decimals on rates, costs and measured quantities — a display choice, set on the
     * organisation profile beside the date format. Money always shows two; a unit cost
     * shows two more than this, because a label costs fractions of a taka.
     */
    decimals: 2,
};

export function configureFormatting(values = {}) {
    if (values.number_locale) settings.locale = values.number_locale;
    if (values.timezone) settings.timezone = values.timezone;
    if (values.date_format) settings.dateFormat = values.date_format;
    if (values.time_format) settings.timeFormat = values.time_format;
    if (values.base_currency) settings.baseCurrency = values.base_currency;
    if (values.week_start) settings.weekStart = values.week_start;
    if (values.decimal_places !== undefined && values.decimal_places !== null) settings.decimals = Number(values.decimal_places);
}

/** The organisation's decimals for rates, costs and measured quantities. */
export function decimals() {
    return settings.decimals;
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

function isoFromLocal(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * Calendar `YYYY-MM-DD` from a date-only value or a Laravel ISO datetime.
 *
 * Date-only fields must not go through `toISOString()`: Dhaka is UTC+6, so midnight local
 * becomes the previous day in UTC, and `2026-08-20T00:00:00.000000Z` is a calendar date,
 * not an instant.
 */
export function isoDate(value) {
    if (value === null || value === undefined || value === '') return '';

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? '' : isoFromLocal(value);
    }

    const match = String(value).trim().match(/^(\d{4})-(\d{2})-(\d{2})/);

    return match ? `${match[1]}-${match[2]}-${match[3]}` : '';
}

const MONTH_NAMES = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

/** A real calendar day as `YYYY-MM-DD`, or '' — 31/02 and month 13 are not dates. */
function isoFromParts(year, month, day) {
    if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return '';
    if (month < 1 || month > 12 || day < 1) return '';

    const date = new Date(year, month - 1, day);

    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
        ? isoFromLocal(date)
        : '';
}

/** Two-digit years: 00–69 are this century, 70–99 the last, the usual spreadsheet convention. */
function fullYear(text) {
    const year = Number(text);

    if (text.length !== 2) return year;

    return year < 70 ? 2000 + year : 1900 + year;
}

/**
 * A date as a person types it, turned into `YYYY-MM-DD`.
 *
 * The day always comes first. This is Bangladesh: `05/10/2026` is the fifth of October, and a
 * parser that guessed the American order for some values would be wrong silently, which is the
 * worst way to be wrong about a delivery date.
 *
 * Accepted:
 *   - `20/10/2026`, `20-10-2026`, `20.10.2026`, `20 10 2026`
 *   - the same with a two-digit year, and with single-digit day or month (`5/3/26`)
 *   - `20 Oct 2026`, `20 October 2026`, `20-Oct-26`, `20Oct2026`
 *   - ISO `2026-10-20`, and a Laravel datetime that starts with it
 *
 * Returns '' for anything else, including a date that does not exist.
 */
export function parseTypedDate(value) {
    if (value === null || value === undefined) return '';

    const text = String(value).trim().toLowerCase();

    if (text === '') return '';

    const iso = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[t ].*)?$/);

    if (iso) return isoFromParts(Number(iso[1]), Number(iso[2]), Number(iso[3]));

    const numeric = text.match(/^(\d{1,2})\s*[/.\- ]\s*(\d{1,2})\s*[/.\- ]\s*(\d{2}|\d{4})$/);

    if (numeric) return isoFromParts(fullYear(numeric[3]), Number(numeric[2]), Number(numeric[1]));

    const named = text.match(/^(\d{1,2})\s*[/.\- ]?\s*([a-z]{3,9})\.?,?\s*[/.\- ]?\s*(\d{2}|\d{4})$/);

    if (named) {
        const month = MONTH_NAMES.indexOf(named[2].slice(0, 3));
        // "sept" is how en-GB abbreviates it, and how this application prints it.
        const known = month !== -1 && ('september'.startsWith(named[2]) || MONTH_NAME_FULL[month].startsWith(named[2]));

        return known ? isoFromParts(fullYear(named[3]), month + 1, Number(named[1])) : '';
    }

    return '';
}

const MONTH_NAME_FULL = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

/** `20/10/2026` — the form a date takes while it is being typed. */
export function typedDate(value) {
    const iso = isoDate(value);

    if (!iso) return '';

    const [year, month, day] = iso.split('-');

    return `${day}/${month}/${year}`;
}

/** Today's calendar date in the browser, never UTC. */
export function todayIso() {
    return isoFromLocal(new Date());
}

/**
 * A stored decimal as something to type: "12000.0000" becomes "12000", null becomes "".
 * For an input's v-model on an edit form, where the database's scale is not the user's.
 */
export function typed(value) {
    if (value === null || value === undefined || value === '') return '';

    const n = Number(value);

    return Number.isFinite(n) ? String(n) : String(value);
}

/**
 * A record from the server with every stored decimal made typeable: "12000.000000" becomes
 * "12000" on the quantity, ids and text are left alone. For the line a form opens with.
 */
export function typedRecord(record) {
    const out = { ...record };

    for (const [key, value] of Object.entries(out)) {
        if (typeof value === 'string' && /^-?\d+\.\d+$/.test(value)) out[key] = typed(value);
    }

    return out;
}

/** A calendar date some whole months on, clamped to the month's last day. */
export function addCalendarMonths(value, months) {
    const parsed = parseCalendarDate(value);

    if (!parsed) return '';

    const day = parsed.getDate();
    parsed.setDate(1);
    parsed.setMonth(parsed.getMonth() + Number(months));
    const last = new Date(parsed.getFullYear(), parsed.getMonth() + 1, 0).getDate();
    parsed.setDate(Math.min(day, last));

    return isoFromLocal(parsed);
}

export function addCalendarDays(value, days) {
    const parsed = parseCalendarDate(value);

    if (!parsed) return '';

    parsed.setDate(parsed.getDate() + Number(days));

    return isoFromLocal(parsed);
}

function parseCalendarDate(value) {
    const iso = isoDate(value);

    if (!iso) return null;

    const [year, month, day] = iso.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function applyFormat(date, format, timeZone = null) {
    return [...format]
        .map((token) => {
            const options = DATE_PARTS[token];

            if (!options) return token;

            return date.toLocaleDateString(DATE_LOCALE, timeZone ? { ...options, timeZone } : options);
        })
        .join('');
}

function renderTime(value) {
    const parsed = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(parsed.getTime())) return '';

    return parsed.toLocaleTimeString(DATE_LOCALE, {
        timeZone: settings.timezone,
        hour: '2-digit',
        minute: '2-digit',
        hour12: settings.timeFormat !== 'HH:mm',
    });
}

/**
 * The number locale decides grouping and separators — 12,34,567 or 1,234,567 — and nothing
 * else. `bn-BD` on its own would also switch the digits to Bengali script (১২,৩৪,৫৬৭), which
 * nobody types into a form, a barcode or a ledger; pinning the numbering system keeps the
 * factory's lakh-and-crore grouping with digits everybody can read and re-enter.
 */
function locale() {
    return `${settings.locale}-u-nu-latn`;
}

/**
 * Dates and times carry month names and "3 days ago", which are language, not number format.
 * The language of the interface is its own setting; the number locale must not drag October
 * into অক্টো just because amounts group in lakhs.
 */
const DATE_LOCALE = 'en-GB';

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

/**
 * A plain figure: grouped the way every other number is, with only the decimals it has.
 *
 * For a count or a reading that is neither money nor a fixed-precision quantity — and for the
 * places that used to call `toLocaleString()` or `toFixed()` themselves, which grouped by the
 * browser's own locale (or not at all) while the rest of the screen followed the organisation's.
 */
export function number(value, maxDecimals = null, minDecimals = 0) {
    const max = maxDecimals ?? settings.decimals;

    return toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: Math.min(minDecimals, max),
        maximumFractionDigits: max,
    });
}

/** Metres and kilograms: fractional, to the organisation's decimals. */
export function qty(value, places = null) {
    const digits = places ?? settings.decimals;

    return toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

/**
 * A measured quantity where it is read, not reckoned: "11,611 m", not "11,611.25 m".
 *
 * On a planning board or a queue the quarter-metre is noise beside eleven thousand of them. A
 * large figure is whole; a small one keeps the decimal that still means something, and never
 * a trailing zero. Documents and anything that is added up use `qty`.
 */
export function qtyRound(value) {
    const n = Math.abs(toNumber(value));
    const digits = n >= 100 ? 0 : n >= 10 ? 1 : settings.decimals;

    return toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
    });
}

/**
 * Money, always labelled with its currency.
 *
 * `currency` may be a code (`'USD'`), a currency object (`{ code: 'USD' }`), or omitted — in
 * which case the amount is in the factory's base currency and says so. Pass `false` for the
 * rare place where the currency is already stated once for a whole block and repeating it on
 * every row would be noise.
 */
export function money(value, currency = undefined) {
    const formatted = toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    if (currency === false) return formatted;

    const code = currencyCode(currency) ?? settings.baseCurrency;

    return code ? `${code} ${formatted}` : formatted;
}

/** The code out of whatever a page happens to hold: a string, a currency row, or nothing. */
export function currencyCode(currency) {
    if (!currency) return null;
    if (typeof currency === 'string') return currency;

    return currency.code ?? currency.currency_code ?? null;
}

/** The factory's own currency, for labelling a total that has no document behind it. */
export function baseCurrency() {
    return settings.baseCurrency;
}

/**
 * A foreign-currency document also states what it is worth in the factory's books, so nobody
 * has to multiply by the exchange rate in their head. Returns null when there is nothing to
 * convert — the document is already in the base currency.
 */
export function inBaseCurrency(value, currency, exchangeRate) {
    const code = currencyCode(currency);
    const rate = toNumber(exchangeRate);

    if (!code || code === settings.baseCurrency || rate === 1 || rate === 0) return null;

    return money(toNumber(value) * rate, settings.baseCurrency);
}

/**
 * Quantity formatted for its unit's dimension: counted units (pieces, sheets, cones) are
 * whole numbers — `3,000.000 pcs` reads as a precision claim that does not exist — while
 * measured units (metres, kilograms) genuinely carry three decimals.
 */
export function qtyFor(value, dimension) {
    return dimension === 'count' ? pcs(value) : qty(value);
}

/**
 * The per-1000 rate, labelled with its currency and its unit.
 *
 * To the organisation's decimals — two by default; a factory that negotiates to the fourth
 * decimal sets four on its profile — and a currency, because `Rate /M 270.2066` beside
 * `Rate /M 17.4429` said nothing about which document was in USD. Same contract as `money()`:
 * pass a code or a currency row, omit it for the factory's own currency, or pass `false`
 * where the block already states it once.
 */
export function ratePerM(value, currency = undefined, { unit = true } = {}) {
    const formatted = toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: settings.decimals,
        maximumFractionDigits: settings.decimals,
    });

    // Said in full. "/M" is the trade's shorthand for "per thousand", and in a factory that
    // measures ribbon it reads as "per metre".
    const suffix = unit ? ' per 1,000 pcs' : '';

    if (currency === false) return `${formatted}${suffix}`;

    const code = currencyCode(currency) ?? settings.baseCurrency;

    return code ? `${code} ${formatted}${suffix}` : `${formatted}${suffix}`;
}

/**
 * The same rate for a cell that sits under a "Rate per 1,000 pcs" heading: the heading has
 * already said the unit, and repeating it on every row is noise.
 */
export function rate(value, currency = undefined) {
    return ratePerM(value, currency, { unit: false });
}

/**
 * Cost per single piece. Two decimals more than a rate, because a label costs fractions of a
 * taka and the fourth decimal is the difference between winning and losing an order — and a
 * currency, because `0.2162` on its own is not obviously money at all.
 */
export function unitCost(value, currency = undefined) {
    const formatted = toNumber(value).toLocaleString(locale(), {
        minimumFractionDigits: settings.decimals + 2,
        maximumFractionDigits: settings.decimals + 2,
    });

    if (currency === false) return formatted;

    const code = currencyCode(currency) ?? settings.baseCurrency;

    return code ? `${code} ${formatted}` : formatted;
}

/**
 * Percentages carry the decimals they need and no more: a 5% tolerance reads `5%`, while a
 * 12.75% overhead keeps its digits. Column scale is a storage decision, not a display one.
 */
export function pct(value, decimals = null) {
    const number = toNumber(value);
    const places = decimals ?? (Number.isInteger(number) ? 0 : 2);

    return `${number.toLocaleString(locale(), { minimumFractionDigits: places, maximumFractionDigits: places })}%`;
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

    const parsed = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(parsed.getTime())) return '—';

    return `${applyFormat(parsed, settings.dateFormat, settings.timezone)} ${renderTime(value)}`;
}

/** Calendar dates (order date, due date) — never shifted by timezone. */
export function date(value) {
    if (!value) return '—';

    const parsed = parseCalendarDate(value);

    return parsed ? applyFormat(parsed, settings.dateFormat) : '—';
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

    const formatter = new Intl.RelativeTimeFormat(DATE_LOCALE, { numeric: 'auto' });

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

/**
 * Words that are not words. Title-casing a key letter by letter gave "Qc Pending", "Po", and
 * for the kinds of import payment "Tt", "Da" and "Dp".
 */
const ACRONYMS = {
    qc: 'QC', po: 'PO', lc: 'LC', grn: 'GRN', ncr: 'NCR', rfq: 'RFQ', bom: 'BOM', fg: 'FG',
    capa: 'CAPA', mrp: 'MRP', coc: 'CoC', pod: 'POD', aql: 'AQL', uom: 'UoM', cad: 'CAD',
    tt: 'TT', da: 'DA', dp: 'DP', grs: 'GRS', fsc: 'FSC', pdf: 'PDF', id: 'ID',
};

/** A stored key as a label: "qc_pending" → "QC Pending", "back_to_back" → "Back To Back". */
export function titleCase(value) {
    if (!value) return '';

    return String(value)
        .replace(/_/g, ' ')
        .split(' ')
        .map((word) => ACRONYMS[word.toLowerCase()] ?? word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export default {
    install(app) {
        app.config.globalProperties.$fmt = {
            pcs,
            number,
            qty,
            qtyFor,
            money,
            currencyCode,
            baseCurrency,
            inBaseCurrency,
            ratePerM,
            rate,
            unitCost,
            pct,
            mm,
            datetime,
            date,
            isoDate,
            todayIso,
            time,
            relative,
            documentNumber,
            titleCase,
        };
    },
};

/**
 * A span of machine time, the way a planner says it: "41 min", "2.7 h", "52,501 h".
 *
 * The board printed raw `planned_minutes` — "3150030 min" — which nobody can place against a
 * shift. Under an hour stays in minutes; an hour or more is hours, with one decimal while the
 * figure is small enough for the decimal to matter.
 */
export function minutes(value) {
    const total = Number(value);

    if (!Number.isFinite(total) || total <= 0) return '0 min';
    if (total < 60) return `${Math.round(total)} min`;

    const hours = total / 60;
    const digits = hours < 100 ? 1 : 0;

    return `${number(hours, digits, digits)} h`;
}
