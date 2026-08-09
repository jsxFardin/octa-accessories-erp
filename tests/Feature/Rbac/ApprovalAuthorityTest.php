<?php

declare(strict_types=1);

use App\Models\User;

/**
 * 06-rbac §5 — a manager is the approval authority the officer is not.
 *
 * The paired roles look identical on the *screens* built so far, because the approval
 * workflows (PO approval, stock adjustment approval) are Phase 2 and Phase 3. The permission
 * model already separates them; this pins that separation so the screens cannot be built
 * against a flattened role model later.
 */
it('gives the store manager approval authority the store keeper lacks', function (): void {
    $keeper = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
    $manager = User::query()->where('email', 'storemanager@maheenlabel.test')->firstOrFail();

    foreach (['stock_adjustment.approve', 'stock_adjustment.post', 'physical_count.approve'] as $permission) {
        expect($manager->hasPermission($permission))->toBeTrue()
            ->and($keeper->hasPermission($permission))->toBeFalse();
    }

    // Both still do the daily work.
    expect($keeper->hasPermission('grn.post'))->toBeTrue()
        ->and($keeper->hasPermission('stock_adjustment.create'))->toBeTrue();
});

it('gives the purchase manager approval authority the purchase officer lacks', function (): void {
    $officer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
    $manager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();

    foreach (['purchase_order.approve', 'purchase_order.send', 'supplier.approve'] as $permission) {
        expect($manager->hasPermission($permission))->toBeTrue()
            ->and($officer->hasPermission($permission))->toBeFalse();
    }

    expect($officer->hasPermission('purchase_order.submit'))->toBeTrue();
});

it('keeps the md out of system administration writes', function (): void {
    $md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();

    // 06-rbac §3 marks the MD as view-only on Users, Settings and Sequences. Reaching the
    // screen is not the same as being able to change anything on it.
    expect($md->hasPermission('user.view_any'))->toBeTrue()
        ->and($md->hasPermission('setting.view_any'))->toBeTrue();

    foreach (['user.create', 'user.update', 'user.assign_role', 'setting.update', 'role.update'] as $permission) {
        expect($md->hasPermission($permission))->toBeFalse();
    }

    $this->actingAs($md)->put('/admin/settings', ['settings' => []])->assertForbidden();
    $this->actingAs($md)->post('/admin/users/1/roles', ['role_ids' => []])->assertForbidden();
});
