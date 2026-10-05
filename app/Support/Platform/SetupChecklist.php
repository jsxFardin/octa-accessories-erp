<?php

declare(strict_types=1);

namespace App\Support\Platform;

use App\Models\User;
use App\Support\Reference\ReferenceRegistry;
use App\Support\Settings\Organisation;
use Illuminate\Support\Facades\DB;

/**
 * What a new installation still has to do before it can take its first order.
 *
 * A fresh system opened on a dashboard of eight zeros and nothing else: no order to put the
 * setup in, and no sign that "Lists" under Configuration was where to begin. This is that
 * order, judged from what is actually in the database, so it cannot drift from the truth and
 * disappears by itself once the work is done.
 *
 * A step is shown only to someone who may carry it out — a checklist of things you cannot do
 * is a list of complaints.
 */
final class SetupChecklist
{
    public function __construct(private readonly Organisation $organisation) {}

    /**
     * @return array{steps: list<array<string, mixed>>, done: int, total: int}|null
     *                                                                              null when there is nothing left for this user to do
     */
    public function for(User $user): ?array
    {
        $steps = array_values(array_filter(
            $this->steps(),
            fn (array $step): bool => $user->hasPermission($step['permission']),
        ));

        $done = count(array_filter($steps, fn (array $step): bool => $step['done']));

        if ($steps === [] || $done === count($steps)) {
            return null;
        }

        return [
            'steps' => array_map(
                fn (array $step): array => array_diff_key($step, ['permission' => true]),
                $steps,
            ),
            'done' => $done,
            'total' => count($steps),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function steps(): array
    {
        $profile = $this->organisation->forFrontend();
        $emptyLists = $this->emptyLists();
        $users = DB::table('users')->where('is_active', true)->count();
        $customers = DB::table('customers')->count();
        $items = DB::table('items')->count();
        $products = DB::table('products')->count();
        $quotations = DB::table('quotations')->count();

        return [
            [
                'key' => 'organisation',
                'title' => 'Enter your company details',
                'detail' => 'Name, address and phone appear on every quotation, delivery note and invoice.',
                'done' => filled($profile['address']) && filled($profile['phone']),
                'href' => '/admin/settings',
                'action' => 'Open settings',
                'permission' => 'setting.view_any',
            ],
            [
                'key' => 'lists',
                'title' => 'Fill in the lists the forms choose from',
                'detail' => $emptyLists === 0
                    ? 'Units, warehouses, machine groups, payment terms and the rest all have entries.'
                    : ($emptyLists === 1 ? '1 list is still empty.' : "{$emptyLists} lists are still empty.")
                        .' Units, warehouses, machine groups and payment terms are needed before anything else can be saved.',
                'done' => $emptyLists === 0,
                'href' => '/setup',
                'action' => 'Open lists',
                'permission' => 'reference_data.view_any',
            ],
            [
                'key' => 'users',
                'title' => 'Add the people who will use the system',
                'detail' => 'Each person gets their own sign-in and a role that decides what they can open.',
                'done' => $users > 1,
                'href' => '/admin/users',
                'action' => 'Add users',
                'permission' => 'user.create',
            ],
            [
                'key' => 'customers',
                'title' => 'Add your first customer',
                'detail' => 'Products, quotations and orders all belong to a customer.',
                'done' => $customers > 0,
                'href' => '/customers/create',
                'action' => 'Add a customer',
                'permission' => 'customer.create',
            ],
            [
                'key' => 'items',
                'title' => 'Add the materials you buy',
                'detail' => 'Yarn, ribbon, ink, cartons — what a bill of materials is made of. They can also be imported from a spreadsheet.',
                'done' => $items > 0,
                'href' => '/items',
                'action' => 'Add materials',
                'permission' => 'item.create',
            ],
            [
                'key' => 'products',
                'title' => 'Set up your first product',
                'detail' => 'A label or tag for one customer. Its own page then walks through specification, artwork, materials and routing.',
                'done' => $products > 0,
                'href' => '/products/create',
                'action' => 'Add a product',
                'permission' => 'product.create',
            ],
            [
                'key' => 'quotation',
                'title' => 'Raise your first quotation',
                'detail' => 'Once a product is set up it can be priced and quoted.',
                'done' => $quotations > 0,
                'href' => '/quotations/create',
                'action' => 'New quotation',
                'permission' => 'quotation.create',
            ],
        ];
    }

    private function emptyLists(): int
    {
        $empty = 0;

        foreach (ReferenceRegistry::all() as $definition) {
            if (! DB::table($definition['table'])->exists()) {
                $empty++;
            }
        }

        return $empty;
    }
}
