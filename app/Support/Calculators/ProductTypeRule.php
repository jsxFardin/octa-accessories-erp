<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * What a product type *does*, as the consumption formulas need it.
 *
 * This used to be an enum with a `match` arm per case, which meant a new product type was a
 * release. The values now live in the `product_types` table and arrive here as a value object,
 * so the calculators stay pure — a costing dispute is still reproducible from numbers typed
 * into a test — while the factory can add "embroidered badge" through Setup.
 *
 * A type nobody has configured gets `neutral()`: no yarn, no ink, no sheets, no tool. That is
 * the conservative reading of an unknown process — it costs nothing it should not.
 */
final readonly class ProductTypeRule
{
    public function __construct(
        public string $code,
        public string $label,
        /** BR-9 — only woven labels consume yarn; the rest consume a pre-made web. */
        public bool $consumesYarn = false,
        /** BR-11 — offset tags are quoted in sheets, not metres. */
        public bool $consumesSheets = false,
        /** BR-10 — ink lay in g/m² per colour. Overridable per item on the item master. */
        public ?float $defaultInkLayGsm = null,
        /** BR-13 — one tool per colour for these processes. */
        public bool $requiresToolPerColour = false,
    ) {}

    public static function neutral(string $code = 'other', ?string $label = null): self
    {
        return new self($code, $label ?? ucfirst(str_replace('_', ' ', $code)));
    }

    public function consumesYarn(): bool
    {
        return $this->consumesYarn;
    }

    public function consumesSheets(): bool
    {
        return $this->consumesSheets;
    }

    public function consumesInk(): bool
    {
        return $this->defaultInkLayGsm !== null;
    }

    public function defaultInkLayGsm(): ?float
    {
        return $this->defaultInkLayGsm;
    }

    public function requiresToolPerColour(): bool
    {
        return $this->requiresToolPerColour;
    }

    public function label(): string
    {
        return $this->label;
    }
}
