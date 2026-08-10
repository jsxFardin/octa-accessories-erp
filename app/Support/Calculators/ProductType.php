<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * What this factory makes (00-overview §2). Each type follows a different route through
 * different machines with different consumables — which is why the production entity is a job
 * card bound to a routing, not a line plan bound to a quantity.
 *
 * The six headline types are the ones 00-overview describes. `Ribbon`, `Tape` and `Other`
 * exist because `products_type_chk` admits them: without a case here, reading a product row
 * carrying one of those values throws a ValueError from `from()` and takes the screen down.
 * They are treated as pre-made web with no default ink lay — a conservative default that
 * costs nothing it should not, and one to revisit when the factory actually sells one.
 */
enum ProductType: string
{
    case Woven = 'woven';
    case Flexo = 'flexo';
    case Screen = 'screen';
    case HeatTransfer = 'heat_transfer';
    case OffsetTag = 'offset_tag';
    case Thermal = 'thermal';
    case Ribbon = 'ribbon';
    case Tape = 'tape';
    case Other = 'other';

    /** BR-10 — ink lay in g/m² per colour. Overridable per item on the item master. */
    public function defaultInkLayGsm(): ?float
    {
        return match ($this) {
            self::Flexo => 1.6,
            self::Screen => 8.0,
            self::OffsetTag => 1.1,
            self::HeatTransfer => 12.0,
            self::Woven, self::Thermal, self::Ribbon, self::Tape, self::Other => null,
        };
    }

    /** BR-9 — only woven labels consume yarn; the rest consume a pre-made web. */
    public function consumesYarn(): bool
    {
        return $this === self::Woven;
    }

    /** BR-11 — offset tags are quoted in sheets, not metres. */
    public function consumesSheets(): bool
    {
        return $this === self::OffsetTag;
    }

    public function consumesInk(): bool
    {
        return $this->defaultInkLayGsm() !== null;
    }

    /** BR-13 — one tool per colour for these processes. */
    public function requiresToolPerColour(): bool
    {
        return in_array($this, [self::Flexo, self::Screen, self::OffsetTag, self::HeatTransfer], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Woven => 'Woven label',
            self::Flexo => 'Flexo printed label',
            self::Screen => 'Screen printed label',
            self::HeatTransfer => 'Heat transfer label',
            self::OffsetTag => 'Offset printed tag / ticket',
            self::Thermal => 'Thermal printed label',
            self::Ribbon => 'Printed ribbon',
            self::Tape => 'Printed tape',
            self::Other => 'Other',
        };
    }
}
