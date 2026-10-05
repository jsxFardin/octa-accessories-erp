<?php

declare(strict_types=1);

use App\Support\Text\Plain;

/*
 * UX audit M-04, M-05. Rule numbers, status keys and permission names are the system's own
 * identifiers; what reaches a screen is the sentence without them.
 */
it('spells a status key out, keeping acronyms', function (string $key, string $words): void {
    expect(Plain::status($key))->toBe($words);
})->with([
    ['pending_approval', 'pending approval'],
    ['qc_pending', 'QC pending'],
    ['in_transit', 'in transit'],
    ['partially_paid', 'partially paid'],
    ['draft', 'draft'],
]);

it('starts a label with a capital', function (): void {
    expect(Plain::statusLabel('qc_pending'))->toBe('QC pending')
        ->and(Plain::statusLabel('pending_approval'))->toBe('Pending approval');
});

it('takes the rule reference off a message', function (string $message, string $plain): void {
    expect(Plain::message($message))->toBe($plain);
})->with([
    ['J3: output 5200.000 exceeds the 5000.000 handed to this operation.', 'Output 5200.000 exceeds the 5000.000 handed to this operation.'],
    ['BR-46: this order takes them past their credit limit.', 'This order takes them past their credit limit.'],
    ['P1-2 · BR-24: lot L-1 is reserved for other jobs.', 'Lot L-1 is reserved for other jobs.'],
    ['Gate 1 — no job card can be released until one of these is approved.', 'No job card can be released until one of these is approved.'],
    ['Final inspection QI-7 is still pending. (P1-1 · QC1)', 'Final inspection QI-7 is still pending.'],
    ['No conversion from [kg] to [m] (BR-3). Add a conversion.', 'No conversion from kg to m. Add a conversion.'],
    ['SalesReturn cannot move from [posted] to [in_transit].', 'SalesReturn cannot move from posted to in transit.'],
]);

it('leaves an ordinary sentence alone', function (string $message): void {
    expect(Plain::message($message))->toBe($message);
})->with([
    'Trip TRP-26-00001 planned.',
    'A4 paper is out of stock.',
    'Lot B2 is on hold.',
    'Enter the name of the person who received the goods.',
    'Version 3 withdrawn. The next upload will be version 3.',
]);

it('passes nothing through as nothing', function (): void {
    expect(Plain::message(null))->toBeNull()->and(Plain::message(''))->toBe('');
});
