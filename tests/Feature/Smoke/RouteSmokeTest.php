<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Opens every GET screen as the implementer.
 *
 * Not a substitute for the behavioural tests — it proves only that no page throws. That is
 * worth having on its own: a mistyped column in a list query is invisible until someone opens
 * the screen, and this opens all of them on every run.
 */
it('renders every screen without erroring', function (): void {
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    // Bindings resolved from the seeded walkthrough, so detail screens get a real record.
    $bindings = [
        'item' => fn () => DB::table('items')->value('id'),
        'customer' => fn () => DB::table('customers')->value('id'),
        'supplier' => fn () => DB::table('suppliers')->value('id'),
        'machine' => fn () => DB::table('machines')->value('id'),
        'product' => fn () => DB::table('products')->value('id'),
        'artwork' => fn () => DB::table('artworks')->value('id'),
        'jobCard' => fn () => DB::table('job_cards')->value('id'),
        'salesOrder' => fn () => DB::table('sales_orders')->value('id'),
        'quotation' => fn () => DB::table('quotations')->value('id'),
        'inquiry' => fn () => DB::table('inquiries')->value('id'),
        'grn' => fn () => DB::table('grns')->value('id'),
        'lot' => fn () => DB::table('stock_lots')->value('id'),
        'inspection' => fn () => DB::table('qc_inspections')->value('id'),
        'purchaseOrder' => fn () => DB::table('purchase_orders')->value('id'),
        'operation' => fn () => DB::table('job_card_operations')->value('id'),
        'routing' => fn () => DB::table('routings')->value('id'),
        'purchaseRequisition' => fn () => DB::table('purchase_requisitions')->value('id'),
    ];

    $failures = [];
    $opened = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        if (str_starts_with($uri, '_') || str_starts_with($uri, 'api/') || str_starts_with($uri, 'storage/')
            || in_array($uri, ['up', 'login', '/'], true)) {
            continue;
        }

        $resolved = '/'.$uri;
        $skip = false;

        foreach ($route->parameterNames() as $parameter) {
            $id = isset($bindings[$parameter]) ? ($bindings[$parameter])() : null;

            if ($id === null) {
                // No seeded record for this screen — nothing to open, so nothing to prove.
                $skip = true;

                break;
            }

            $resolved = preg_replace('/\{'.$parameter.'\??}/', (string) $id, $resolved);
        }

        if ($skip) {
            continue;
        }

        $response = $this->get($resolved);
        $opened++;

        if ($response->getStatusCode() >= 500) {
            $failures[] = $resolved.' → '.$response->getStatusCode().' '
                .substr(strip_tags((string) $response->getContent()), 0, 200);
        }
    }

    expect($failures)->toBe([])
        ->and($opened)->toBeGreaterThan(30);
});
