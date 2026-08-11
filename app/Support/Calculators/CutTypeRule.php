<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * BR-4 — the gap a cut type adds to the label pitch, and whether it needs a tool (BR-13).
 *
 * Lifted off a `cut_types` row. A spec may still override the gap, which is why the spec
 * carries its own `cut_gap_mm` column; this is the default it falls back to.
 */
final readonly class CutTypeRule
{
    public function __construct(
        public string $code,
        public string $label,
        public float $defaultCutGapMm = 0.0,
        public bool $requiresTool = false,
    ) {}

    public static function neutral(string $code = 'straight_cut', ?string $label = null): self
    {
        return new self($code, $label ?? ucfirst(str_replace('_', ' ', $code)));
    }

    public function defaultCutGapMm(): float
    {
        return $this->defaultCutGapMm;
    }

    public function requiresTool(): bool
    {
        return $this->requiresTool;
    }

    public function label(): string
    {
        return $this->label;
    }
}
