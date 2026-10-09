<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The supplier page showed two tables and none of the supplier: not what was on order, not what
 * was owed, not even their email. It is now told the account in figures, in the factory's
 * currency, because one supplier's orders come in several.
 */
it('gives the supplier page the account in the factory currency, with terms and contacts', function (): void {
    $admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();

    $supplierId = (int) DB::table('suppliers')->insertGetId([
        'code' => 'SUP-ACCT-T',
        'name' => 'Account test supplier',
        'currency_id' => DB::table('currencies')->value('id'),
        'payment_term_id' => DB::table('payment_terms')->value('id'),
        'lead_time_days' => 21,
        'is_approved' => true,
    ]);

    DB::table('supplier_contacts')->insert(['supplier_id' => $supplierId, 'name' => 'Rina', 'is_primary' => true]);

    $order = fn (string $status, float $total, float $rate, ?string $expected) => DB::table('purchase_orders')->insert([
        'supplier_id' => $supplierId,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'currency_id' => DB::table('currencies')->value('id'),
        'exchange_rate' => $rate,
        'total' => $total,
        'status' => $status,
        'order_date' => '2026-09-01',
        'expected_date' => $expected,
    ]);

    $order('sent', 100, 120, '2026-10-20');
    $order('approved', 50, 1, '2026-10-12');
    // Neither a draft nor a finished order is "on order".
    $order('draft', 999, 1, '2026-10-01');
    $order('received', 999, 1, '2026-09-10');

    DB::table('supplier_bills')->insert([
        'supplier_id' => $supplierId,
        'bill_no' => 'B-1',
        'bill_date' => '2026-09-05',
        'due_date' => '2026-09-20',
        'currency_id' => DB::table('currencies')->value('id'),
        'exchange_rate' => 2,
        'total' => 80,
        'paid_amount' => 30,
        'status' => 'partially_paid',
    ]);

    $this->travelTo('2026-10-06 10:00:00');

    $this->actingAs($admin)->get("/suppliers/{$supplierId}")
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->component('MasterData/Suppliers/Show')
            ->where('stats.open_order_count', 2)
            ->where('stats.open_order_value', fn ($value) => abs($value - 12050.0) < 0.001)
            ->where('stats.next_expected_on', '2026-10-12')
            ->where('stats.last_order_on', '2026-09-01')
            ->where('stats.unpaid_bill_count', 1)
            ->where('stats.outstanding', fn ($value) => abs($value - 100.0) < 0.001)
            ->where('stats.overdue', fn ($value) => abs($value - 100.0) < 0.001)
            ->where('contacts.0.name', 'Rina')
            ->has('terms.currency')
            ->has('terms.payment_term'));
});

/*
 * A supplier's contacts, the materials they sell and how to reach them are kept on the
 * supplier's own page. The tables existed; nothing a buyer could open wrote to them.
 */
describe('kept on the supplier page', function (): void {
    beforeEach(function (): void {
        $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
        $this->supplierId = (int) DB::table('suppliers')->insertGetId(['code' => 'SUP-KEPT-T', 'name' => 'Kept test supplier']);
        $this->otherId = (int) DB::table('suppliers')->insertGetId(['code' => 'SUP-KEPT-O', 'name' => 'Another supplier']);
        $this->itemId = (int) DB::table('items')->where('status', 'active')->value('id');
        $this->currencyId = (int) DB::table('currencies')->value('id');
    });

    it('adds, changes and removes a contact, keeping one primary', function (): void {
        $url = "/suppliers/{$this->supplierId}/contacts";

        $this->actingAs($this->admin)->post($url, ['name' => 'Rina', 'is_primary' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($url, ['name' => 'Karim', 'email' => 'karim@example.com', 'is_primary' => true])->assertSessionHasNoErrors();

        $contacts = DB::table('supplier_contacts')->where('supplier_id', $this->supplierId)->orderBy('id')->get();

        expect($contacts)->toHaveCount(2)
            ->and((bool) $contacts[0]->is_primary)->toBeFalse()
            ->and((bool) $contacts[1]->is_primary)->toBeTrue();

        $this->actingAs($this->admin)->put("{$url}/{$contacts[0]->id}", ['name' => 'Rina Akter', 'phone' => '+880 1700 000000', 'is_primary' => false])
            ->assertSessionHasNoErrors();

        expect(DB::table('supplier_contacts')->where('id', $contacts[0]->id)->value('name'))->toBe('Rina Akter');

        // Another supplier's page cannot reach this supplier's people.
        $this->actingAs($this->admin)->delete("/suppliers/{$this->otherId}/contacts/{$contacts[0]->id}")->assertNotFound();

        $this->actingAs($this->admin)->delete("{$url}/{$contacts[0]->id}")->assertSessionHasNoErrors();

        expect(DB::table('supplier_contacts')->where('supplier_id', $this->supplierId)->count())->toBe(1);

        $this->actingAs($this->admin)->post($url, ['name' => '', 'email' => 'not-an-email'])->assertSessionHasErrors(['name', 'email']);
    });

    it('links a material once, with its own terms, and unlinks it', function (): void {
        $url = "/suppliers/{$this->supplierId}/items";

        $this->actingAs($this->admin)->post($url, [
            'item_id' => $this->itemId, 'supplier_code' => 'X-1', 'last_rate' => 12.5,
            'currency_id' => $this->currencyId, 'lead_time_days' => null, 'moq' => 500,
        ])->assertSessionHasNoErrors();

        $link = DB::table('supplier_items')->where('supplier_id', $this->supplierId)->first();

        expect($link->supplier_code)->toBe('X-1')
            ->and($link->lead_time_days)->toBeNull()
            ->and((float) $link->moq)->toBe(500.0);

        // The same material twice is one row edited, not two rows.
        $this->actingAs($this->admin)->post($url, ['item_id' => $this->itemId])->assertSessionHasErrors('item_id');
        // A rate says what it is in.
        $this->actingAs($this->admin)->put("{$url}/{$link->id}", ['last_rate' => 9, 'currency_id' => null])->assertSessionHasErrors('currency_id');

        $this->actingAs($this->admin)->put("{$url}/{$link->id}", ['supplier_code' => 'X-2', 'lead_time_days' => 14])->assertSessionHasNoErrors();

        expect(DB::table('supplier_items')->where('id', $link->id)->value('lead_time_days'))->toBe(14);

        $this->actingAs($this->admin)->delete("/suppliers/{$this->otherId}/items/{$link->id}")->assertNotFound();
        $this->actingAs($this->admin)->delete("{$url}/{$link->id}")->assertSessionHasNoErrors();

        expect(DB::table('supplier_items')->where('supplier_id', $this->supplierId)->count())->toBe(0);
    });

    it('changes email, phone and address without the rest of the record', function (): void {
        $this->actingAs($this->admin)->put("/suppliers/{$this->supplierId}/reach", [
            'email' => 'sales@example.com', 'phone' => '+44 20 0000 0000', 'address' => '1 Mill Lane, Leeds',
        ])->assertSessionHasNoErrors();

        $row = DB::table('suppliers')->where('id', $this->supplierId)->first();

        expect($row->email)->toBe('sales@example.com')
            ->and($row->address)->toBe('1 Mill Lane, Leeds')
            ->and($row->name)->toBe('Kept test supplier');
    });

    it('refuses all of it to someone who may only read suppliers', function (): void {
        $reader = User::query()->get()->first(fn (User $user) => $user->can('supplier.view_any') && ! $user->can('supplier.update'));

        if ($reader === null) {
            $this->markTestSkipped('No seeded user can read suppliers without being able to change them.');
        }

        $this->actingAs($reader)->post("/suppliers/{$this->supplierId}/contacts", ['name' => 'X'])->assertForbidden();
        $this->actingAs($reader)->post("/suppliers/{$this->supplierId}/items", ['item_id' => $this->itemId])->assertForbidden();
        $this->actingAs($reader)->put("/suppliers/{$this->supplierId}/reach", ['email' => 'x@example.com'])->assertForbidden();
    });
});
