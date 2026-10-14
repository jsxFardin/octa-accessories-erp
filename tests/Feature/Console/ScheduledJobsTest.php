<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * GAP D-05 / C-04 / P-15 — the nightly moves the specification describes and nothing ran.
 */
it('marks issued invoices past their due date overdue and tells accounts', function (): void {
    $issued = function (string $number, string $dueDate): SalesInvoice {
        return SalesInvoice::query()->create([
            'number' => $number,
            'customer_id' => DB::table('customers')->value('id'),
            'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
            'invoice_date' => now()->subDays(40)->toDateString(),
            'due_date' => $dueDate,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => 'issued',
        ]);
    };

    $invoice = $issued('INV-TEST-OVERDUE', now()->subDay()->toDateString());
    $fresh = $issued('INV-TEST-CURRENT', now()->addDays(10)->toDateString());

    $this->artisan('invoices:mark-overdue')->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('overdue');

    expect($fresh->fresh()->status)->toBe('issued');

    $accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();

    expect(DB::table('notifications')->where('notifiable_id', $accounts->id)
        ->where('data->dedupe_key', 'invoice:overdue:'.$invoice->id)->exists())->toBeTrue();
});

it('expires certificates past their date and reminds compliance of the ones about to', function (): void {
    $lapsed = DB::table('certifications')->where('scheme', 'GRS')->where('status', 'active')->firstOrFail();
    $soon = DB::table('certifications')->where('scheme', 'FSC')->where('status', 'active')->firstOrFail();

    DB::table('certifications')->where('id', $lapsed->id)->update(['expires_on' => now()->subDay()->toDateString()]);
    DB::table('certifications')->where('id', $soon->id)->update(['expires_on' => now()->addDays(10)->toDateString(), 'reminder_days' => 60]);

    $this->artisan('certifications:expire')->assertSuccessful();

    expect(DB::table('certifications')->where('id', $lapsed->id)->value('status'))->toBe('expired')
        ->and(DB::table('certifications')->where('id', $soon->id)->value('status'))->toBe('active')
        ->and(DB::table('audit_logs')->where('auditable_type', 'certifications')->where('auditable_id', $lapsed->id)
            ->where('event', 'status_changed')->exists())->toBeTrue();

    $compliance = User::query()->where('email', 'compliance@octapussolution.com')->firstOrFail();
    $key = 'certification:expiring:'.$soon->id.':'.now()->addDays(10)->toDateString();

    expect(DB::table('notifications')->where('notifiable_id', $compliance->id)->where('data->dedupe_key', $key)->exists())->toBeTrue();

    // Once per certificate per date.
    $this->artisan('certifications:expire')->assertSuccessful();

    expect(DB::table('notifications')->where('data->dedupe_key', $key)->count())
        ->toBe(DB::table('notifications')->where('data->dedupe_key', $key)->distinct('notifiable_id')->count('notifiable_id'));
});

it('marks available lots past their expiry date expired and leaves other statuses alone', function (): void {
    $available = DB::table('stock_lots')->where('status', 'available')->where('balance_qty', '>', 0)->firstOrFail();
    $blocked = DB::table('stock_lots')->where('status', '!=', 'available')->where('id', '!=', $available->id)->first()
        ?? $available;

    DB::table('stock_lots')->where('id', $available->id)->update(['expiry_date' => now()->subDay()->toDateString()]);

    if ($blocked->id !== $available->id) {
        DB::table('stock_lots')->where('id', $blocked->id)->update(['expiry_date' => now()->subDay()->toDateString()]);
    }

    $this->artisan('stock:expire-lots')->assertSuccessful();

    expect(DB::table('stock_lots')->where('id', $available->id)->value('status'))->toBe('expired');

    if ($blocked->id !== $available->id) {
        expect(DB::table('stock_lots')->where('id', $blocked->id)->value('status'))->toBe($blocked->status);
    }

    // BR-39 — and it is no longer offered for issue.
    expect(DB::table('stock_lots')->where('id', $available->id)->where('status', 'available')->exists())->toBeFalse();
});

it('schedules all three nightly', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    foreach (['invoices:mark-overdue', 'certifications:expire', 'stock:expire-lots'] as $name) {
        expect($commands->contains(fn (string $command): bool => str_contains($command, $name)))->toBeTrue();
    }
});
