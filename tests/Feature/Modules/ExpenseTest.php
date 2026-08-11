<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\Expense;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->accounts = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'accounts'))->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();

    $this->payload = [
        'expense_date' => now()->toDateString(),
        'expense_category_id' => DB::table('expense_categories')->where('code', 'FUEL')->value('id'),
        'payee' => 'Padma Filling Station',
        'description' => 'Generator diesel, 200 litres',
        'currency_id' => DB::table('currencies')->value('id'),
        'exchange_rate' => 1,
        'amount' => 24000,
        'tax_amount' => 3600,
        'method' => 'cash',
    ];
});

it('raises an expense as a draft with a number and a total', function (): void {
    $this->actingAs($this->accounts)->post('/expenses', $this->payload)->assertRedirect();

    $expense = Expense::query()->latest('id')->firstOrFail();

    expect($expense->status)->toBe('draft')
        ->and($expense->number)->toStartWith('EXP-')
        ->and((float) $expense->total)->toBe(27600.0);
});

it('will not let somebody approve their own spend', function (): void {
    // The one rule that makes an expense module worth having.
    $this->actingAs($this->accounts)->post('/expenses', $this->payload);

    $expense = Expense::query()->latest('id')->firstOrFail();

    $this->actingAs($this->accounts)->post("/expenses/{$expense->id}/transition", ['status' => 'pending_approval']);

    $this->actingAs($this->accounts)
        ->postJson("/expenses/{$expense->id}/transition", ['status' => 'approved'])
        ->assertStatus(422);

    expect($expense->fresh()->status)->toBe('pending_approval');
});

it('lets a second person with the right approve it', function (): void {
    $this->actingAs($this->accounts)->post('/expenses', $this->payload);

    $expense = Expense::query()->latest('id')->firstOrFail();

    $this->actingAs($this->accounts)->post("/expenses/{$expense->id}/transition", ['status' => 'pending_approval']);

    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/transition", ['status' => 'approved'])
        ->assertRedirect();

    expect($expense->fresh()->status)->toBe('approved')
        ->and($expense->fresh()->approved_by)->toBe($this->admin->id)
        ->and($expense->fresh()->approved_at)->not->toBeNull();
});

it('refuses a jump straight from draft to paid', function (): void {
    $this->actingAs($this->accounts)->post('/expenses', $this->payload);

    $expense = Expense::query()->latest('id')->firstOrFail();

    $this->actingAs($this->admin)->postJson("/expenses/{$expense->id}/transition", ['status' => 'paid'])
        ->assertStatus(422);
});

it('freezes an approved expense against editing', function (): void {
    // An approval attached to an amount that then changed is not an approval.
    $this->actingAs($this->accounts)->post('/expenses', $this->payload);

    $expense = Expense::query()->latest('id')->firstOrFail();

    $this->actingAs($this->accounts)->post("/expenses/{$expense->id}/transition", ['status' => 'pending_approval']);
    $this->actingAs($this->admin)->post("/expenses/{$expense->id}/transition", ['status' => 'approved']);

    $this->actingAs($this->accounts)
        ->putJson("/expenses/{$expense->id}", [...$this->payload, 'amount' => 90000])
        ->assertStatus(422);

    expect((float) $expense->fresh()->amount)->toBe(24000.0);
});

it('keeps spend out of the totals until it is real', function (): void {
    $this->actingAs($this->accounts)->post('/expenses', $this->payload);

    $expense = Expense::query()->latest('id')->firstOrFail();

    $draftTotals = $this->actingAs($this->accounts)->get('/expenses')
        ->viewData('page')['props']['totals']['committed'];

    $this->actingAs($this->accounts)->post("/expenses/{$expense->id}/transition", ['status' => 'pending_approval']);

    $liveTotals = $this->actingAs($this->accounts)->get('/expenses')
        ->viewData('page')['props']['totals']['committed'];

    // A draft is somebody typing, not money committed.
    expect($liveTotals - $draftTotals)->toBe(27600.0);
});

it('refuses an expense to someone without the right', function (): void {
    $operator = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'operator'))->firstOrFail();

    expect($operator->hasPermission('expense.view_any'))->toBeFalse();

    $this->actingAs($operator)->get('/expenses')->assertForbidden();
    $this->actingAs($operator)->postJson('/expenses', $this->payload)->assertForbidden();
});

it('seeds the categories a label factory actually spends on', function (): void {
    $codes = DB::table('expense_categories')->pluck('code')->all();

    expect($codes)->toContain('FUEL', 'CNF', 'DUTY', 'BANKCH')
        // Import charges are their own kind: they are the ones that end up inside a lot cost.
        ->and(DB::table('expense_categories')->where('kind', 'import')->count())->toBeGreaterThan(0);
});
