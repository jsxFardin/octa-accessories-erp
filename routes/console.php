<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ncr:notify-overdue')
    ->dailyAt('01:00')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

/*
 * AD-6 — the nightly reconciliation `StockPostingService` documents but nothing ran.
 *
 * `stock_lots.balance_qty` and `stock_balances` are caches over an append-only ledger, correct
 * only while every write goes through that service. A bypassed path — a raw UPDATE, a
 * migration, a repair script — drifts them apart silently, and every screen that reads stock
 * reads the cache. Without this the first symptom is a physical count that will not tie out.
 *
 * It reports and does not repair: the difference is the evidence of the bug that caused it.
 */
Schedule::command('stock:reconcile')
    ->dailyAt('02:00')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

/*
 * The worker heartbeat. Notifications are queued, so with nothing draining the queue the inbox
 * is silently always empty — no error, no log line. The command already existed and was never
 * scheduled, which is the same failure mode it exists to detect.
 */
Schedule::command('queue:health')
    ->hourly()
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

/*
 * A sent quotation past its valid-until date stops being an offer. The state machine allowed
 * the move and nothing made it, so every unanswered quotation read "Sent" for ever.
 */
Schedule::command('quotations:expire')
    ->dailyAt('00:30')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

/*
 * FN-6 — an invoice past its due date is overdue, and accounts hear about it that morning.
 * The transition and the notification existed; only a person remembering to press the button
 * ever made the move.
 */
Schedule::command('invoices:mark-overdue')
    ->dailyAt('00:45')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

/*
 * CP-1 — certificates past their date stop being claimable overnight (BR-43 reads the
 * status), and the ones inside their reminder window are raised with compliance and the MD.
 */
Schedule::command('certifications:expire')
    ->dailyAt('01:15')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();

/*
 * IN-8 / BR-39 — a lot past its expiry date is not issuable. The status is what the issue
 * screen and the FIFO suggestion read.
 */
Schedule::command('stock:expire-lots')
    ->dailyAt('01:30')
    ->timezone((string) config('app.display_timezone', 'Asia/Dhaka'))
    ->withoutOverlapping();
