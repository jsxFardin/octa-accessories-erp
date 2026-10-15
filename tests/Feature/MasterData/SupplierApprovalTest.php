<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * GAP S-12 — MD-5 AC2: supplier approval is `supplier.approve`, not a checkbox under
 * `supplier.update`. The permission was seeded and never consulted.
 */
beforeEach(function (): void {
    $this->officer = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();
    $this->manager = User::query()->where('email', 'purchasemanager@octapussolution.com')->firstOrFail();
    $this->supplier = Supplier::query()->where('is_approved', false)->first()
        ?? tap(Supplier::query()->firstOrFail())->forceFill(['is_approved' => false])->save();
    $this->supplier = Supplier::query()->where('is_approved', false)->firstOrFail();

    expect($this->officer->hasPermission('supplier.update'))->toBeTrue()
        ->and($this->officer->hasPermission('supplier.approve'))->toBeFalse()
        ->and($this->manager->hasPermission('supplier.approve'))->toBeTrue();
});

it('ignores an approval ticked by someone who may only edit the supplier', function (): void {
    $this->actingAs($this->officer)->put("/suppliers/{$this->supplier->id}", [
        'code' => $this->supplier->code,
        'name' => $this->supplier->name,
        'is_approved' => true,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($this->supplier->fresh()->is_approved)->toBeFalse();

    $this->actingAs($this->officer)->post("/suppliers/{$this->supplier->id}/approve")->assertForbidden();
});

it('lets a purchase manager approve from the supplier page, audited', function (): void {
    $this->actingAs($this->manager)->post("/suppliers/{$this->supplier->id}/approve")->assertSessionHas('success');

    expect($this->supplier->fresh()->is_approved)->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_type', Supplier::class)->where('auditable_id', $this->supplier->id)
            ->where('event', 'updated')->where('new_values', 'like', '%is_approved%')->exists())->toBeTrue();
});
