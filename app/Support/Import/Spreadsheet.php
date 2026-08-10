<?php

declare(strict_types=1);

namespace App\Support\Import;

use Generator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reading a spreadsheet without loading one.
 *
 * Both readers stream: a thousand-row file is a thousand rows in memory one at a time, not a
 * parsed document. The distinction matters less for the 1 000-row ceiling than for what
 * happens when somebody ignores it and uploads the whole item master.
 */
class Spreadsheet
{
    /** What a person can hand us. `.xls` is not here: it is a different, older format. */
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'ods'];

    /**
     * Rows as flat arrays of scalars, first row included (it is the header).
     *
     * @return Generator<int, list<mixed>>
     */
    public static function rows(string $path, string $extension): Generator
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'ods' => new OdsReader,
            default => new CsvReader,
        };

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_values($row->toArray());
                }

                // Only the first sheet. A workbook with a second tab of notes is common;
                // importing those notes as customers is not what anybody meant.
                break;
            }
        } finally {
            $reader->close();
        }
    }
}
