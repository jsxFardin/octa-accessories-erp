<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * One typed line of a cost sheet (BR-14).
 *
 * `formulaRef` records which business rule produced the number, so a costing dispute is
 * answerable in seconds rather than by reverse-engineering a spreadsheet
 * (02-database-schema §3.4).
 */
final readonly class CostLine
{
    public function __construct(
        public int $seq,
        public string $costType,
        public string $basis,
        public float $qty,
        public float $rate,
        public float $amount,
        public string $formulaRef,
        public ?string $description = null,
    ) {}

    public static function of(
        int $seq,
        string $costType,
        string $basis,
        float $qty,
        float $rate,
        string $formulaRef,
        ?string $description = null,
    ): self {
        // BR-47: money is rounded half-up at line level, then summed, so the printed
        // document always foots.
        return new self(
            $seq,
            $costType,
            $basis,
            $qty,
            $rate,
            round($qty * $rate, 4),
            $formulaRef,
            $description,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'seq' => $this->seq,
            'cost_type' => $this->costType,
            'basis' => $this->basis,
            'qty' => round($this->qty, 6),
            'rate' => round($this->rate, 4),
            'amount' => round($this->amount, 4),
            'formula_ref' => $this->formulaRef,
            'description' => $this->description,
        ];
    }
}
