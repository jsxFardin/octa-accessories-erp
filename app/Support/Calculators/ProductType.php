<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * The six things this factory makes (00-overview §2). Each follows a different route through
 * different machines with different consumables — which is why the production entity is a job
 * card bound to a routing, not a line plan bound to a quantity.
 */
enum ProductType: string
{
    case Woven = 'woven';
    case Flexo = 'flexo';
    case Screen = 'screen';
    case HeatTransfer = 'heat_transfer';
    case OffsetTag = 'offset_tag';
    case Thermal = 'thermal';

    /** BR-10 — ink lay in g/m² per colour. Overridable per item on the item master. */
    public function defaultInkLayGsm(): ?float
    {
        return match ($this) {
            self::Flexo => 1.6,
            self::Screen => 8.0,
            self::OffsetTag => 1.1,
            self::HeatTransfer => 12.0,
            self::Woven, self::Thermal => null,
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
        };
    }
}
