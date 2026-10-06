/**
 * Chart chrome and series colours, as hex because the canvas renderer cannot read CSS
 * variables. Taken from the Tailwind slate ramp and the kit's ink.
 *
 * The categorical order (teal-600, indigo-500, amber-600) passed the dataviz six checks on
 * a light surface: lightness band, chroma floor, colour-vision separation, normal-vision
 * separation and contrast. Assign it in order, never cycle past it. Rose is status —
 * overdue, failed — and never a series colour.
 */
export const CHART = {
    ink: '#334155',
    muted: '#64748b',
    gridline: '#e2e8f0',
    axis: '#cbd5e1',
    surface: '#ffffff',
    series: ['#0d9488', '#6366f1', '#d97706'],
    critical: '#e11d48',
    deEmphasis: '#cbd5e1',
};
