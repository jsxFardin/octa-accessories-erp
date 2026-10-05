<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\States\TransitionDenied;

/*
 * UX audit M-04, M-05. The code that refuses something names its rule, and that name reached
 * the banner: "J3: output exceeds input", "… (P1-1 · QC1)", "moved to pending_approval",
 * "You do not have the [job_card.close] permission".
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
});

it('shows a flash without its rule number', function (): void {
    $this->withSession(['error' => 'J3: output 5200 exceeds the 5000 handed to this operation.', 'success' => 'Saved.'])
        ->get('/reports')
        ->assertInertia(fn ($page) => $page
            ->where('flash.error', 'Output 5200 exceeds the 5000 handed to this operation.')
            ->where('flash.success', 'Saved.'));
});

it('shows a validation message without its rule number, under the same field', function (): void {
    $productId = (int) Illuminate\Support\Facades\DB::table('products')->value('id');

    // A woven-label spec with no web width: the server's own sentence starts "BR-5: …".
    $this->from("/products/{$productId}")->post("/products/{$productId}/specs", [
        'label_width_mm' => 30, 'label_height_mm' => 50, 'colours' => 1,
        'colour_list' => [['index' => 1, 'name' => 'White']],
    ])->assertRedirect();

    $this->get('/reports')->assertInertia(fn ($page) => $page
        ->where('errors.web_width_mm', fn (string $message): bool => str_starts_with($message, 'A web width is needed') || str_starts_with($message, 'Enter a web width')));
});

it('says a refused status change with the verb for where it was going', function (string $class, string $from, string $to, string $sentence): void {
    expect(TransitionDenied::notAllowed($class, $from, $to)->getMessage())->toBe($sentence);
})->with([
    ['PurchaseOrder', 'pending_approval', 'received', 'Purchase order is pending approval, so it cannot be received.'],
    ['JobCard', 'qc_pending', 'released', 'Job card is QC pending, so it cannot be released.'],
    ['SalesReturn', 'posted', 'cancelled', 'Sales return is posted, so it cannot be cancelled.'],
    ['Quotation', 'accepted', 'sent', 'Quotation is accepted, so it cannot be marked as sent.'],
    ['DeliveryChallan', 'draft', 'in_transit', 'Delivery note is draft, so it cannot be marked as in transit.'],
    ['JobCard', 'draft', 'on_hold', 'Job card is draft, so it cannot be put on hold.'],
    ['Ncr', 'open', 'investigating', 'NCR is open, so it cannot be put under investigation.'],
    // A status with no verb of its own: the fallback names it, capitalised.
    ['Ncr', 'open', 'preventive', 'NCR is open, so it cannot be moved to Preventive.'],
    ['TestReport', 'draft', 'archived_copy', 'Test report is draft, so it cannot be moved to Archived copy.'],
]);

it('says a missing permission as an action, with its article or plural', function (string $key, string $action): void {
    $denied = TransitionDenied::notPermitted($key);

    expect($denied->permission)->toBe($key)
        ->and($denied->getMessage())->toBe("You do not have permission to {$action}. Ask an administrator to give your role that permission.")
        ->and($denied->getMessage())->not->toContain($key)->not->toContain('[');
})->with([
    ['job_card.close', 'close a job card'],
    ['sales_invoice.view_any', 'see the list of invoices'],
    ['inquiry.view', 'open an inquiry'],
    ['ncr.create', 'create an NCR'],
    ['rfq.update', 'edit an RFQ'],
    ['bom.activate', 'activate a bill of materials'],
    ['bom.export', 'export bills of materials'],
    ['grn.post', 'post a goods receipt'],
    ['delivery_challan.issue', 'issue a delivery note'],
    ['job_card.waive_material', 'release a job card without all its material'],
    ['user.assign_role', 'give a user a role'],
    ['uom.create', 'create a unit'],
    ['currency.export', 'export currencies'],
    ['trip.view_own', 'see your own trips'],
    ['credit_note.refund', 'refund a credit note'],
    ['mrp.run', 'run the material plan'],
    // An action with no template of its own still reads as a sentence.
    ['warehouse.audit_trail', 'audit trail a warehouse'],
]);

it('keeps the rule on a guard refusal without printing it', function (): void {
    $denied = TransitionDenied::guard('P1-1 · QC1', 'Final inspection QI-7 is still pending.');

    expect($denied->rule)->toBe('P1-1 · QC1')
        ->and($denied->getMessage())->toBe('Final inspection QI-7 is still pending.');
});
