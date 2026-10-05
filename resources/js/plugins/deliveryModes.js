/**
 * How a delivery note's goods leave the factory, in the words the dispatch office uses.
 *
 * The stored keys (`own_fleet`, `customer_pickup`…) were being title-cased onto the screen.
 */
export const DELIVERY_MODES = [
    { value: 'own_fleet', label: 'Own vehicle', hint: 'Goes out on one of our vehicles. Plan a trip for it next.' },
    { value: 'courier', label: 'Courier', hint: 'Handed to a courier company.' },
    { value: 'customer_pickup', label: 'Customer pickup', hint: 'The customer collects it from the factory.' },
    { value: 'freight_forwarder', label: 'Freight forwarder', hint: 'Handed to a forwarder for export.' },
];

export function deliveryModeLabel(mode) {
    return DELIVERY_MODES.find((option) => option.value === mode)?.label ?? mode ?? '';
}

/** True when someone else carries the goods, so there is a carrier and a tracking number to record. */
export function carriedByOthers(mode) {
    return mode === 'courier' || mode === 'freight_forwarder';
}
