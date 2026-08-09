<?php

declare(strict_types=1);

namespace App\Support\Numbering;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BR-34 — gap-free, per-document-type, per-year document numbering.
 *
 * The sequence row is locked FOR UPDATE inside the caller's transaction, so the number and
 * the document it belongs to are committed together or not at all. Sequences are deliberately
 * NOT cached in Redis: gaps and duplicates in a VAT-relevant series are an audit finding
 * (02-database-schema §3.1).
 *
 * Numbers are assigned on the first transition out of `draft`, never on opening a blank form
 * (05-workflows §13). Cancelled documents keep their number.
 */
class NumberAllocator
{
    /**
     * Allocate the next number for a document type.
     *
     * @param  string  $documentType  e.g. 'sales_order'
     * @param  string|null  $seriesKey  defaults to the two-digit year, so numbering resets annually
     */
    public function next(string $documentType, ?string $seriesKey = null): string
    {
        $seriesKey ??= $this->currentSeriesKey();

        if (! DB::transactionLevel()) {
            throw new RuntimeException(
                "Number allocation for [{$documentType}] must run inside the transaction that "
                .'inserts the document (BR-34), otherwise a rolled-back save burns a number.',
            );
        }

        $row = DB::table('number_sequences')
            ->where('document_type', $documentType)
            ->where('series_key', $seriesKey)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new UnknownDocumentSeries(
                "No number sequence for document type [{$documentType}] series [{$seriesKey}]. "
                .'Seed it in NumberSequenceSeeder before the first document of the year.',
            );
        }

        /** @var object{id: int, prefix: string, next_number: int, padding: int} $row */
        DB::table('number_sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $row->next_number + 1]);

        return $this->format($row->prefix, $seriesKey, $row->next_number, $row->padding);
    }

    /**
     * Lot numbers use a date-based series (L{YYMMDD}-{#####}) rather than a yearly one
     * (01-domain-model §5), so the series key is the date, not the year.
     */
    public function nextLotNumber(): string
    {
        return $this->next('lot', now()->format('ymd'));
    }

    /**
     * BR-35 — the printed reference of a revised document is `{number}/R{n}`.
     */
    public function withRevision(?string $number, int $revisionNo): string
    {
        if ($number === null) {
            return '(unnumbered)';
        }

        return $revisionNo > 0 ? "{$number}/R{$revisionNo}" : $number;
    }

    public function currentSeriesKey(): string
    {
        return now()->format('y');
    }

    private function format(string $prefix, string $seriesKey, int $number, int $padding): string
    {
        $padded = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

        // Lot numbers embed the date in the prefix position: L{YYMMDD}-{#####}
        return $prefix === 'L'
            ? "L{$seriesKey}-{$padded}"
            : "{$prefix}-{$seriesKey}-{$padded}";
    }
}
