<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CP-5 AC3 / C3 — closing a chain-of-custody period locks its transactions.
 *
 * `coc_transactions.is_locked` was rendered on the compliance screen and set by nothing. The
 * column existed, the invariant was documented, `coc.create` and `coc.lock_period` were both
 * real permissions, and neither had a route behind it. A chain of custody an auditor can still
 * be shown one month and have rows added to the next is not a chain of custody, and G5 turns
 * on being able to say "this is what that month was".
 */
beforeEach(function (): void {
    $this->officer = User::query()->where('email', 'compliance@octapussolution.com')->firstOrFail();

    $this->period = ['scheme' => 'GRS', 'year' => (int) now()->format('Y'), 'month' => (int) now()->format('n')];

    DB::table('coc_transactions')->insert([
        'scheme' => 'GRS',
        'direction' => 'input',
        'period_year' => $this->period['year'],
        'period_month' => $this->period['month'],
        'qty' => 100,
        'claim_pct' => 100,
        'is_locked' => false,
    ]);

    $this->close = fn (array $overrides = []) => $this->actingAs($this->officer)->post('/compliance/close-period', [
        'scheme' => $this->period['scheme'],
        'period_year' => $this->period['year'],
        'period_month' => $this->period['month'],
        ...$overrides,
    ]);
});

it('coc: closing a period locks every transaction in it', function (): void {
    ($this->close)()->assertRedirect();

    expect(DB::table('coc_transactions')
        ->where('scheme', 'GRS')
        ->where('period_year', $this->period['year'])
        ->where('period_month', $this->period['month'])
        ->where('is_locked', false)
        ->exists())->toBeFalse();
});

it('coc: refuses to close a period twice', function (): void {
    ($this->close)()->assertRedirect();
    ($this->close)()->assertSessionHas('error');
});

it('coc: refuses to close a period that has nothing in it', function (): void {
    ($this->close)(['period_year' => 2001, 'period_month' => 1])->assertSessionHas('error');
});

it('coc: refuses a new certified movement into a closed period', function (): void {
    // The half that makes the lock mean something. Locking the rows that exist is not a close
    // if a back-dated receipt can write a fresh unlocked row into the same month.
    ($this->close)();

    $guard = app(App\Modules\Compliance\Services\CocPeriodGuard::class);

    expect(fn () => $guard->assertOpenNow('GRS'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('coc: leaves another scheme and another month open', function (): void {
    ($this->close)();

    $guard = app(App\Modules\Compliance\Services\CocPeriodGuard::class);

    // Closing GRS for this month says nothing about FSC, or about last month.
    $guard->assertOpenNow('FSC');
    $guard->assertOpen('GRS', $this->period['year'] - 1, 1);
})->throwsNoExceptions();

it('coc: keeps the close away from someone who may only read the ledger', function (): void {
    $reader = User::query()->where('email', 'auditor@octapussolution.com')->firstOrFail();

    expect($reader->hasPermission('coc.lock_period'))->toBeFalse();

    $this->actingAs($reader)->post('/compliance/close-period', [
        'scheme' => 'GRS',
        'period_year' => $this->period['year'],
        'period_month' => $this->period['month'],
    ])->assertForbidden();
});

it('coc: makes someone say so before signing off an impossible conversion factor', function (): void {
    // More certified goods leaving than were consumed is the figure an auditor challenges
    // first. Closing it is allowed — the ceiling may be wrong — but not silently.
    DB::table('coc_transactions')->insert([
        'scheme' => 'GRS',
        'direction' => 'output',
        'period_year' => $this->period['year'],
        'period_month' => $this->period['month'],
        'qty' => 99999,
        'claim_pct' => 100,
        'is_locked' => false,
    ]);

    $ceiling = DB::table('certifications as c')
        ->join('certification_scopes as s', 's.certification_id', '=', 'c.id')
        ->where('c.scheme', 'GRS')
        ->value('s.max_conversion_factor');

    if ($ceiling === null) {
        $this->markTestSkipped('No GRS certification scope to carry a ceiling.');
    }

    ($this->close)()->assertSessionHas('error');

    expect(DB::table('coc_transactions')->where('scheme', 'GRS')->where('is_locked', true)->exists())
        ->toBeFalse();

    ($this->close)(['acknowledge_breach' => true])->assertSessionHas('success');
});
