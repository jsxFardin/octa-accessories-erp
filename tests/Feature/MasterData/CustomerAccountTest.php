<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\CustomerContact;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The customer page as an account view and the list as a book of business: contacts that can
 * be maintained, exposure that counts open orders, and the relationship's documents.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
});

it('adds, edits and removes a contact on the customer, keeping one primary', function (): void {
    CustomerContact::query()->where('customer_id', $this->customer->id)->delete();

    $this->actingAs($this->admin)
        ->post("/customers/{$this->customer->id}/contacts", ['name' => 'Farida Rahman', 'designation' => 'Merchandising manager', 'email' => 'farida@example.com', 'is_primary' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post("/customers/{$this->customer->id}/contacts", ['name' => 'Kamal Hossain', 'phone' => '+880 1700 000000', 'is_primary' => true])
        ->assertSessionHasNoErrors();

    $contacts = CustomerContact::query()->where('customer_id', $this->customer->id)->orderBy('id')->get();

    expect($contacts)->toHaveCount(2)
        ->and($contacts[0]->is_primary)->toBeFalse()
        ->and($contacts[1]->is_primary)->toBeTrue();

    $this->actingAs($this->admin)
        ->put("/customers/{$this->customer->id}/contacts/{$contacts[0]->id}", ['name' => 'Farida Rahman', 'designation' => 'Head of sourcing', 'is_primary' => false])
        ->assertSessionHasNoErrors();

    expect($contacts[0]->fresh()->designation)->toBe('Head of sourcing');

    $this->actingAs($this->admin)
        ->delete("/customers/{$this->customer->id}/contacts/{$contacts[0]->id}")
        ->assertRedirect();

    expect(CustomerContact::query()->where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('refuses to touch a contact through another customer', function (): void {
    $other = Customer::query()->where('id', '!=', $this->customer->id)->first()
        ?? Customer::query()->create(['code' => 'CUST-T-OTHER', 'name' => 'Other Apparel', 'kind' => $this->customer->kind, 'is_active' => true]);
    $contact = CustomerContact::query()->create(['customer_id' => $other->id, 'name' => 'Someone Else']);

    $this->actingAs($this->admin)
        ->put("/customers/{$this->customer->id}/contacts/{$contact->id}", ['name' => 'Hijacked'])
        ->assertNotFound();

    expect($contact->fresh()->name)->toBe('Someone Else');
});

it('counts open orders into the credit exposure, not only invoices', function (): void {
    $order = DB::table('sales_orders')->where('customer_id', $this->customer->id)->first();

    if ($order === null) {
        $this->markTestSkipped('The customer has no order to expose.');
    }

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'confirmed', 'total' => 1000, 'exchange_rate' => 1]);

    $this->actingAs($this->admin)
        ->get("/customers/{$this->customer->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $stats = $page->toArray()['props']['stats'];

            expect((float) $stats['open_order_value'])->toBeGreaterThanOrEqual(1000.0)
                ->and((float) $stats['exposure'])->toEqual(round((float) $stats['outstanding'] + (float) $stats['open_order_value'], 2))
                ->and($stats)->toHaveKeys(['overdue', 'last_order_on', 'quotations_out', 'inquiries_open', 'lifetime_invoiced', 'credit_limit']);
        });
});

it('shows the relationship on the page: contacts, inquiries, quotations, invoices and price lists', function (): void {
    $this->actingAs($this->admin)
        ->get("/customers/{$this->customer->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('MasterData/Customers/Show')
            ->has('contacts')
            ->has('inquiries')
            ->has('quotations')
            ->has('invoices')
            ->has('priceLists')
            ->has('customer.payment_term')
            ->has('customer.currency'));
});

it('lists what is on order and owed for every customer, and filters by kind', function (): void {
    $kind = (string) $this->customer->kind;

    $this->actingAs($this->admin)
        ->get('/customers?kind='.urlencode($kind))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($kind): void {
            $rows = $page->toArray()['props']['customers']['data'];

            expect($rows)->not->toBeEmpty();

            foreach ($rows as $row) {
                expect($row['kind'])->toBe($kind)
                    ->and($row)->toHaveKeys(['open_order_value', 'open_order_count', 'outstanding', 'overdue', 'last_order_on', 'quotations_out']);
            }
        });
});
