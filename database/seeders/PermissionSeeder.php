<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The single source of the permission catalogue, generated from 06-rbac.
 *
 * Naming is `<module>.<action>` (06-rbac §1). Standard actions are view_any, view, create,
 * update, delete, export; transition actions match the state machines; exceptional actions
 * are spelled out (`override_margin`, `waive_material`, `release_credit_hold`) so that
 * granting one is a deliberate act rather than a side effect of a wildcard.
 *
 * A route that references a permission not defined here fails PermissionCatalogueTest.
 */
class PermissionSeeder extends Seeder
{
    /** Standard CRUD set, applied to every resource in RESOURCES. */
    private const CRUD = ['view_any', 'view', 'create', 'update', 'delete', 'export'];

    /** resource => [module label, extra non-CRUD actions] */
    private const RESOURCES = [
        // Platform
        'user' => ['Platform', ['assign_role']],
        'role' => ['Platform', []],
        'setting' => ['Platform', []],
        'number_sequence' => ['Platform', []],
        'audit_log' => ['Platform', []],

        // Master data
        // `import` sits only on the four flat lists a spreadsheet can state in full
        // (App\Support\Import\ImportRegistry). It is separate from `create` because loading
        // four hundred records in one upload is not the same act as adding one, and because
        // it writes over records that already exist.
        'item' => ['Master data', ['import']],
        'machine' => ['Master data', []],
        'customer' => ['Master data', ['import']],
        'supplier' => ['Master data', ['approve', 'import']],
        'warehouse' => ['Master data', []],
        'uom' => ['Master data', []],
        'currency' => ['Master data', []],
        'tax' => ['Master data', []],
        'employee' => ['Master data', []],
        // The shared gate for the Setup lists that had no permission of their own. Lookups
        // that already had one — uom, currency, tax, warehouse, machine, item — keep it, so
        // no role's rights widen because a screen appeared.
        'reference_data' => ['Master data', []],

        // Engineering
        'product' => ['Engineering', ['import']],
        'product_spec' => ['Engineering', ['make_current']],
        'artwork' => ['Engineering', ['submit', 'approve']],
        'bom' => ['Engineering', ['activate']],
        'routing' => ['Engineering', []],
        'tool' => ['Engineering', []],

        // Commercial
        'inquiry' => ['Commercial', ['submit', 'close']],
        'quotation' => ['Commercial', ['send', 'revise', 'accept', 'reject']],
        'cost_sheet' => ['Commercial', ['override_margin']],
        'price_list' => ['Commercial', []],
        'sales_order' => ['Commercial', ['confirm', 'cancel', 'close', 'short_close', 'release_credit_hold', 'override_tolerance', 'amend']],
        'sample_request' => ['Commercial', ['dispatch']],

        // Supply
        'purchase_requisition' => ['Supply', ['submit', 'approve']],
        'rfq' => ['Supply', ['send']],
        'purchase_order' => ['Supply', ['submit', 'approve', 'send', 'cancel', 'close']],
        'grn' => ['Supply', ['post', 'cancel']],
        'supplier_bill' => ['Supply', ['post']],

        // Inventory
        'stock_lot' => ['Inventory', ['print_barcode']],
        'stock_issue' => ['Inventory', ['post']],
        'stock_transfer' => ['Inventory', ['post']],
        'stock_adjustment' => ['Inventory', ['post', 'approve']],
        'physical_count' => ['Inventory', ['approve']],

        // Operations
        'production_plan' => ['Operations', ['publish']],
        'mrp' => ['Operations', ['run']],
        'job_card' => ['Operations', ['release', 'close', 'cancel', 'waive_material', 'hold']],
        'operation' => ['Operations', ['start', 'log', 'finish', 'skip']],
        'downtime' => ['Operations', []],
        'waste' => ['Operations', []],

        // Assurance
        'qc_inspection' => ['Assurance', ['post', 'concession']],
        'lab_test' => ['Assurance', []],
        'test_report' => ['Assurance', ['issue']],
        'ncr' => ['Assurance', ['close']],
        'certification' => ['Assurance', []],
        'coc' => ['Assurance', ['reconcile', 'lock_period']],

        // Fulfilment
        'packing_list' => ['Fulfilment', ['pack']],
        'delivery_challan' => ['Fulfilment', ['issue', 'deliver', 'return']],
        'trip' => ['Fulfilment', ['start', 'complete', 'view_own']],
        'trip_stop' => ['Fulfilment', ['update']],
        'pod' => ['Fulfilment', ['create']],
        'export_document' => ['Fulfilment', []],

        // Trade finance & import. Raw material is imported (00-overview §2), so the credit
        // and the consignment are documents of their own, and `allocate` is separated from
        // `update` because it rewrites inventory valuation (BR-36).
        'letter_of_credit' => ['Supply', ['open', 'amend']],
        'import_shipment' => ['Supply', ['cost', 'allocate']],

        // Money
        'bank_account' => ['Money', []],
        'expense' => ['Money', ['approve', 'pay']],
        'sales_invoice' => ['Money', ['issue', 'cancel']],
        'receipt' => ['Money', ['allocate']],
        'credit_note' => ['Money', ['approve']],
        'payment' => ['Money', ['allocate']],

        // Reporting
        'report' => ['Reporting', ['dashboard']],
    ];

    public function run(): void
    {
        $rows = [];

        foreach (self::RESOURCES as $resource => [$module, $extra]) {
            foreach ([...self::CRUD, ...$extra] as $action) {
                $rows[] = [
                    'name' => "{$resource}.{$action}",
                    'module' => $module,
                    'label' => $this->label($resource, $action),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('permissions')->upsert($chunk, ['name'], ['module', 'label']);
        }

        User::query()->each(fn (User $user) => $user->forgetPermissionCache());

        $this->command->info(count($rows).' permissions seeded.');
    }

    private function label(string $resource, string $action): string
    {
        $resourceLabel = ucfirst(str_replace('_', ' ', $resource));
        $actionLabel = match ($action) {
            'view_any' => 'List',
            'view' => 'View',
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'export' => 'Export',
            default => ucfirst(str_replace('_', ' ', $action)),
        };

        return "{$actionLabel} {$resourceLabel}";
    }

    /** @return list<string> Every permission name, for tests and the role seeder. */
    public static function catalogue(): array
    {
        $names = [];

        foreach (self::RESOURCES as $resource => [, $extra]) {
            foreach ([...self::CRUD, ...$extra] as $action) {
                $names[] = "{$resource}.{$action}";
            }
        }

        return $names;
    }

    /** @return list<string> */
    public static function resources(): array
    {
        return array_keys(self::RESOURCES);
    }
}
