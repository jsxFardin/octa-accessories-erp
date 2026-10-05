<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * UX audit H-50. A database seeded before a document type existed had no number series for it,
 * and numbering the first such document failed with a server error.
 */
it('adds a series the database lacks and leaves existing ones exactly as they are', function (): void {
    $year = now()->format('y');

    DB::table('number_sequences')->where('document_type', 'sales_return')->where('series_key', $year)->delete();
    DB::table('number_sequences')->where('document_type', 'inquiry')->where('series_key', $year)
        ->update(['next_number' => 4321]);

    $migration = require database_path('migrations/2026_10_05_000100_add_missing_number_sequences.php');
    $migration->up();

    $restored = DB::table('number_sequences')->where('document_type', 'sales_return')->where('series_key', $year)->first();

    expect($restored)->not->toBeNull()
        ->and($restored->prefix)->toBe('SR')
        ->and((int) $restored->next_number)->toBe(1)
        // A live series is not reset.
        ->and((int) DB::table('number_sequences')->where('document_type', 'inquiry')
            ->where('series_key', $year)->value('next_number'))->toBe(4321);

    // Running it again changes nothing.
    $count = DB::table('number_sequences')->count();
    $migration->up();

    expect(DB::table('number_sequences')->count())->toBe($count);
});
