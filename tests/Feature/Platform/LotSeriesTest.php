<?php

declare(strict_types=1);

use App\Support\Numbering\NumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily lot series L{YYMMDD} vivifies itself on first use. Before this, the series
 * existed only for the seed day — the first GRN or FG receipt of the next day failed with
 * UnknownDocumentSeries, which is a production line stopped by a number format.
 */
beforeEach(function (): void {
    $this->allocator = app(NumberAllocator::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Allocate inside a transaction, the way every real caller does (BR-34). */
function nextLot(object $test): string
{
    return DB::transaction(fn (): string => $test->allocator->nextLotNumber());
}

it('creates a missing day series automatically and numbers from 1', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-05 09:00:00'));

    expect(DB::table('number_sequences')->where('document_type', 'lot')->where('series_key', '270305')->exists())
        ->toBeFalse();

    expect(nextLot($this))->toBe('L270305-00001')
        ->and(nextLot($this))->toBe('L270305-00002');

    // Exactly one series row for the day — vivified once, then reused.
    expect(DB::table('number_sequences')->where('document_type', 'lot')->where('series_key', '270305')->count())
        ->toBe(1);
});

it('reuses an existing series and preserves its counter', function (): void {
    $today = now()->format('ymd');

    // Seed-day series exists with an advanced counter; vivification must not reset it.
    DB::table('number_sequences')->updateOrInsert(
        ['document_type' => 'lot', 'series_key' => $today],
        ['prefix' => 'L', 'padding' => 5, 'next_number' => 7],
    );

    expect(nextLot($this))->toBe("L{$today}-00007")
        ->and(nextLot($this))->toBe("L{$today}-00008");
});

it('rolls a month and a year boundary onto fresh series without touching history', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 23:00:00'));
    expect(nextLot($this))->toBe('L261231-00001');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 06:00:00'));
    expect(nextLot($this))->toBe('L270101-00001');

    // Yesterday's series is history, not a casualty: counter intact.
    expect((int) DB::table('number_sequences')
        ->where('document_type', 'lot')->where('series_key', '261231')->value('next_number'))
        ->toBe(2);
});

it('leaves the yearly document sequences alone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-05 09:00:00'));

    $before = DB::table('number_sequences')->where('document_type', '!=', 'lot')
        ->orderBy('id')->get(['id', 'series_key', 'next_number']);

    nextLot($this);

    $after = DB::table('number_sequences')->where('document_type', '!=', 'lot')
        ->orderBy('id')->get(['id', 'series_key', 'next_number']);

    // Vivification is scoped to the lot series; yearly VAT-relevant series stay hand-seeded.
    expect($after)->toEqual($before);
});

it('does not corrupt the sequence when the allocating transaction rolls back', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-05 09:00:00'));

    try {
        DB::transaction(function (): void {
            $this->allocator->nextLotNumber();

            throw new RuntimeException('the document insert failed');
        });
    } catch (RuntimeException) {
        // The rollback un-creates the vivified row and un-burns the number.
    }

    // A fresh attempt starts clean: series recreated, numbering from 1.
    expect(nextLot($this))->toBe('L270305-00001');
});

it('vivifies through insertOrIgnore so a concurrent duplicate insert is benign', function (): void {
    // The race-safety mechanism, proven at the database: a second INSERT of the same
    // (document_type, series_key) is IGNOREd by the unique key rather than erroring or
    // duplicating, and allocation always increments the one surviving row under its lock.
    // (A true cross-connection race cannot be staged inside RefreshDatabase's wrapping
    // transaction — the second connection would block on the first's uncommitted insert,
    // which is exactly the serialisation that makes the pattern safe in production.)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-06-01 08:00:00'));

    $first = nextLot($this);

    // A "lost" duplicate vivification attempt, as the slower of two racers would issue.
    DB::table('number_sequences')->insertOrIgnore([
        'document_type' => 'lot', 'series_key' => '270601', 'prefix' => 'L', 'padding' => 5,
    ]);

    $second = nextLot($this);

    expect($first)->toBe('L270601-00001')
        ->and($second)->toBe('L270601-00002')
        ->and(DB::table('number_sequences')->where('document_type', 'lot')->where('series_key', '270601')->count())
        ->toBe(1);
});
