<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * BR-4 — default cut gap by cut type. The default is a starting point; a product spec may
 * override it, which is why the spec carries its own `cut_gap_mm` column.
 */
enum CutType: string
{
    case HotCut = 'hot_cut';
    case Ultrasonic = 'ultrasonic';
    case Laser = 'laser';
    case DieCut = 'die_cut';
    case StraightCut = 'straight_cut';

    public function defaultCutGapMm(): float
    {
        return match ($this) {
            self::HotCut, self::Ultrasonic => 2.0,
            self::Laser => 1.5,
            self::DieCut => 3.0,
            self::StraightCut => 1.0,
        };
    }

    /** BR-13 — a die-cut product needs a cutting die regardless of print process. */
    public function requiresTool(): bool
    {
        return $this === self::DieCut;
    }

    public function label(): string
    {
        return match ($this) {
            self::HotCut => 'Hot cut',
            self::Ultrasonic => 'Ultrasonic cut',
            self::Laser => 'Laser cut',
            self::DieCut => 'Die cut',
            self::StraightCut => 'Straight cut',
        };
    }
}
