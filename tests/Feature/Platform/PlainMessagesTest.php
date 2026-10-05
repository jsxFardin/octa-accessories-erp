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

it('says a refused status change in words', function (): void {
    expect(TransitionDenied::notAllowed('PurchaseOrder', 'pending_approval', 'received')->getMessage())
        ->toBe('Purchase order is pending approval, so it cannot be changed to received.')
        ->and(TransitionDenied::notAllowed('JobCard', 'qc_pending', 'released')->getMessage())
        ->toBe('Job card is QC pending, so it cannot be changed to released.');
});

it('says a missing permission as the action it allows, and keeps the key for the logs', function (): void {
    $denied = TransitionDenied::notPermitted('job_card.close');

    expect($denied->permission)->toBe('job_card.close')
        ->and($denied->getMessage())->toStartWith('You do not have permission to ')
        ->and($denied->getMessage())->not->toContain('job_card.close')->not->toContain('[');
});

it('keeps the rule on a guard refusal without printing it', function (): void {
    $denied = TransitionDenied::guard('P1-1 · QC1', 'Final inspection QI-7 is still pending.');

    expect($denied->rule)->toBe('P1-1 · QC1')
        ->and($denied->getMessage())->toBe('Final inspection QI-7 is still pending.');
});
