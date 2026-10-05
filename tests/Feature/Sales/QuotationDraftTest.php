<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Sales\Models\Quotation;

/**
 * UX audit H-08. A quotation could not be saved until every line had a product and a computed
 * rate. When a product could not be priced the merchandiser had to abandon the
 * quotation, fix the product, and type it all again. A draft may now hold unpriced lines;
 * sending is what refuses them.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();

    $this->payload = [
        'customer_id' => $this->customer->id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $this->currency->id,
        'lines' => [
            ['description' => 'Woven main label, product still to be set up', 'product_id' => null, 'qty' => 5000, 'rate_per_m' => null],
        ],
    ];
});

it('saves a draft whose line has no product or rate yet', function (): void {
    $this->actingAs($this->merchandiser)->post('/quotations', $this->payload)->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $line = $quotation->lines()->firstOrFail();

    expect($quotation->status)->toBe('draft')
        ->and($line->product_id)->toBeNull()
        ->and((float) $line->qty)->toBe(5000.0)
        ->and((float) $line->rate_per_m)->toBe(0.0)
        ->and((float) $quotation->total)->toBe(0.0);
});

it('still refuses to send a quotation with an unpriced line', function (): void {
    $this->actingAs($this->merchandiser)->post('/quotations', $this->payload);

    $quotation = Quotation::query()->latest('id')->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'sent'])
        ->assertSessionHas('error');

    expect($quotation->fresh()->status)->toBe('draft');
});

it('still requires every line to say what is being quoted', function (): void {
    $this->payload['lines'][0]['description'] = '';

    $this->actingAs($this->merchandiser)->post('/quotations', $this->payload)
        ->assertSessionHasErrors('lines.0.description');
});

it('still requires a quantity on every line', function (): void {
    $this->payload['lines'][0]['qty'] = null;

    $this->actingAs($this->merchandiser)->post('/quotations', $this->payload)
        ->assertSessionHasErrors('lines.0.qty');
});
