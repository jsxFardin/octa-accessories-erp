<?php

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives an existing installation the number series it was seeded too early to have.
 *
 * Number sequences are created by the reference-data seeder, which runs once. A document type
 * added after that — customer returns and refunds were — has no series on a database that was
 * already live, and the first attempt to number one failed with "No number sequence for
 * document type [sales_return]", shown to the user as a server error (UX audit H-50).
 *
 * This inserts, for the current year, each series the seeder defines and the database lacks.
 * It never touches a series that exists: no prefix, padding or next number is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $year = now()->format('y');

        foreach (ReferenceDataSeeder::documentSeries() as $type => [$prefix, $padding]) {
            $exists = DB::table('number_sequences')
                ->where('document_type', $type)
                ->where('series_key', $year)
                ->exists();

            if (! $exists) {
                DB::table('number_sequences')->insert([
                    'document_type' => $type,
                    'series_key' => $year,
                    'prefix' => $prefix,
                    'padding' => $padding,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: a series that documents have since been numbered from must stay.
    }
};
