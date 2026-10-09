/**
 * Dotted keys on a `ResourceForm`.
 *
 * A field whose key is `attributes.diameter_mm` reads and writes `form.attributes.diameter_mm`,
 * so the form posts `attributes: { diameter_mm: 5 }` and a Laravel error keyed
 * `attributes.diameter_mm` lands on the field that asked for it. Nothing deeper than one level
 * is needed: the item master's specification attributes are a flat bag under one key.
 */
export function getPath(object, key) {
    if (!key.includes('.')) return object?.[key];

    return key.split('.').reduce((value, part) => (value == null ? undefined : value[part]), object);
}

export function setPath(object, key, value) {
    if (!key.includes('.')) {
        object[key] = value;

        return;
    }

    const parts = key.split('.');
    const last = parts.pop();
    let target = object;

    for (const part of parts) {
        if (target[part] === null || typeof target[part] !== 'object') target[part] = {};
        target = target[part];
    }

    target[last] = value;
}
