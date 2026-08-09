<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The 23 roles and their permission bundles, transcribed from the matrix in 06-rbac §2–3.
 *
 * Grammar of a grant:
 *   'item.*'        every action on the resource            (● in the matrix)
 *   'item:read'     view_any, view and export only          (○ in the matrix)
 *   'item.approve'  one named action                        (A in the matrix)
 *
 * `super_admin` holds no rows: `User::hasPermission()` short-circuits for it, so the escape
 * hatch cannot be revoked by editing a role and locking everyone out.
 */
class RoleSeeder extends Seeder
{
    private const READ_ACTIONS = ['view_any', 'view', 'export'];

    /** @var array<string, array{label: string, grants: list<string>}> */
    private const ROLES = [
        'super_admin' => [
            'label' => 'Super administrator',
            'grants' => [],   // implicit — see User::hasPermission()
        ],

        'md' => [
            'label' => 'Managing Director',
            // Reads everything, approves exceptions, enters nothing. An MD who enters data
            // is an MD who breaks the audit trail (06-rbac §6).
            'grants' => [
                '*:read',
                'cost_sheet.override_margin', 'sales_order.release_credit_hold', 'sales_order.short_close',
                'artwork.approve', 'purchase_order.approve', 'stock_adjustment.approve',
                'qc_inspection.concession', 'credit_note.approve', 'job_card.waive_material',
                'report.dashboard',
            ],
        ],

        'admin' => [
            'label' => 'System administrator',
            'grants' => [
                'user.*', 'role.*', 'setting.*', 'number_sequence.*', 'audit_log.*', 'employee.*',
                'item.*', 'machine.*', 'customer.*', 'supplier.*', 'warehouse.*', 'uom.*',
                'currency.*', 'tax.*', 'report:read',
            ],
        ],

        'merchandiser' => [
            'label' => 'Merchandiser',
            'grants' => [
                'inquiry.*', 'quotation.*', 'cost_sheet.*', 'sales_order.*', 'sample_request.*',
                'customer.*', 'artwork.*', 'export_document.*',
                'product:read', 'product_spec:read', 'bom:read', 'item:read', 'job_card:read',
                'stock_lot:read', 'sales_invoice:read', 'report.*',
            ],
        ],

        'sales_manager' => [
            'label' => 'Sales manager',
            'grants' => [
                'inquiry.*', 'quotation.*', 'cost_sheet.*', 'sales_order.*', 'sample_request.*',
                'customer.*', 'artwork.*', 'export_document.*',
                'cost_sheet.override_margin', 'sales_order.short_close',
                'product:read', 'product_spec:read', 'bom:read', 'item:read', 'job_card:read',
                'stock_lot:read', 'sales_invoice:read', 'credit_note:read', 'report.*',
            ],
        ],

        'designer' => [
            'label' => 'Designer / studio',
            'grants' => [
                'artwork.*', 'product_spec.create', 'product_spec.update', 'product_spec:read',
                'product:read', 'sample_request:read', 'customer:read', 'tool:read', 'report:read',
            ],
        ],

        'engineer' => [
            'label' => 'Product engineer',
            'grants' => [
                'product.*', 'product_spec.*', 'bom.*', 'routing.*', 'tool.*',
                'machine.*', 'item:read', 'artwork:read', 'job_card:read',
                'production_plan:read', 'qc_inspection:read', 'report.*',
            ],
        ],

        'planner' => [
            'label' => 'Production planner',
            'grants' => [
                'production_plan.*', 'mrp.*', 'job_card.*',
                'purchase_requisition.view_any', 'purchase_requisition.view',
                'purchase_requisition.create', 'purchase_requisition.update',
                'purchase_requisition.submit', 'purchase_requisition.export',
                'machine.*', 'stock_lot.*', 'stock_issue:read',
                'job_card.waive_material',
                'sales_order:read', 'product:read', 'product_spec:read', 'bom:read', 'routing:read',
                'tool.*', 'item:read', 'supplier:read', 'purchase_order:read', 'grn:read', 'report.*',
            ],
        ],

        'production_supervisor' => [
            'label' => 'Production supervisor',
            'grants' => [
                'job_card.*', 'operation.*', 'downtime.*', 'waste.*', 'packing_list.*',
                'ncr.*', 'sample_request.*',
                'job_card.release', 'job_card.hold',
                'machine:read', 'stock_lot:read', 'stock_issue.*', 'qc_inspection:read',
                'production_plan:read', 'product_spec:read', 'artwork:read', 'report.*',
            ],
        ],

        'operator' => [
            'label' => 'Machine operator',
            // Exactly four permissions (06-rbac §6). The terminal opens nothing else.
            'grants' => ['operation.start', 'operation.log', 'operation.finish', 'downtime.create'],
        ],

        'store_keeper' => [
            'label' => 'Store keeper',
            // Raises and posts the day's work; approves none of it. A wildcard here would
            // sweep `.approve` in with the rest and quietly make the manager tier decorative.
            'grants' => [
                'stock_lot.*', 'stock_issue.*', 'stock_transfer.*',
                'physical_count.view_any', 'physical_count.view', 'physical_count.create',
                'physical_count.update', 'physical_count.export',
                'stock_adjustment.create', 'stock_adjustment.update', 'stock_adjustment:read',
                'grn.*', 'item.*',
                'purchase_requisition.view_any', 'purchase_requisition.view',
                'purchase_requisition.create', 'purchase_requisition.update',
                'purchase_requisition.submit', 'purchase_requisition.export',
                'job_card:read', 'purchase_order:read', 'packing_list.*', 'report.*',
            ],
        ],

        'store_manager' => [
            'label' => 'Store manager',
            'grants' => [
                'stock_lot.*', 'stock_issue.*', 'stock_transfer.*', 'stock_adjustment.*',
                'physical_count.*', 'grn.*', 'item.*', 'purchase_requisition.*',
                'stock_adjustment.approve', 'physical_count.approve',
                'job_card:read', 'purchase_order:read', 'packing_list.*', 'report.*',
            ],
        ],

        'qc_inspector' => [
            'label' => 'QC inspector',
            'grants' => [
                'qc_inspection.*', 'ncr.*', 'lab_test:read', 'test_report:read',
                'grn.*', 'packing_list.*', 'sample_request.*',
                'job_card:read', 'stock_lot:read', 'product_spec:read', 'artwork:read', 'report.*',
            ],
        ],

        'quality_manager' => [
            'label' => 'Quality manager',
            'grants' => [
                'qc_inspection.*', 'qc_inspection.concession', 'ncr.*', 'lab_test.*',
                'test_report.*', 'coc.*', 'certification:read',
                'grn.*', 'packing_list.*', 'sample_request.*', 'credit_note:read',
                'job_card:read', 'stock_lot:read', 'product_spec:read', 'artwork:read', 'report.*',
            ],
        ],

        'lab_technician' => [
            'label' => 'Laboratory technician',
            'grants' => [
                'lab_test.*', 'test_report.*', 'qc_inspection:read',
                'stock_lot:read', 'product_spec:read', 'customer:read', 'ncr:read', 'report.*',
            ],
        ],

        'compliance_officer' => [
            'label' => 'Compliance officer',
            'grants' => [
                'certification.*', 'coc.*', 'ncr.*', 'test_report:read', 'lab_test:read',
                'grn:read', 'stock_lot:read', 'packing_list:read', 'delivery_challan:read',
                'job_card:read', 'customer:read', 'supplier:read', 'audit_log:read',
                'export_document:read', 'report.*',
            ],
        ],

        'purchase_officer' => [
            'label' => 'Purchase officer',
            // Drafts and submits; the manager approves. `supplier.*` is spelled out for the
            // same reason — supplier approval is a purchasing decision, not data entry.
            'grants' => [
                'purchase_requisition.view_any', 'purchase_requisition.view',
                'purchase_requisition.create', 'purchase_requisition.update',
                'purchase_requisition.submit', 'purchase_requisition.export',
                'rfq.*', 'purchase_order.create', 'purchase_order.update',
                'purchase_order.submit', 'purchase_order:read', 'grn.*',
                'supplier.view_any', 'supplier.view', 'supplier.create', 'supplier.update',
                'supplier.export',
                'item.*', 'supplier_bill.*', 'stock_lot:read', 'report.*',
            ],
        ],

        'purchase_manager' => [
            'label' => 'Purchase manager',
            'grants' => [
                'purchase_requisition.*', 'rfq.*', 'purchase_order.*', 'grn.*',
                'supplier.*', 'supplier.approve', 'item.*', 'supplier_bill.*',
                'purchase_order.approve', 'stock_lot:read', 'report.*',
            ],
        ],

        'dispatch_officer' => [
            'label' => 'Dispatch officer',
            'grants' => [
                'packing_list.*', 'delivery_challan.*', 'trip.*', 'trip_stop.*', 'pod.*',
                'export_document.*', 'stock_lot.*',
                'sales_order:read', 'job_card:read', 'customer:read', 'sales_invoice:read', 'report.*',
            ],
        ],

        'driver' => [
            'label' => 'Driver',
            // Scoped to trips where they are the assigned driver (06-rbac §6).
            'grants' => ['trip.view_own', 'trip_stop.update', 'pod.create'],
        ],

        'accounts' => [
            'label' => 'Accounts',
            'grants' => [
                'sales_invoice.*', 'receipt.*', 'credit_note.*', 'supplier_bill.*', 'payment.*',
                'credit_note.approve', 'sales_order.release_credit_hold',
                'customer.*', 'supplier.*',
                'sales_order:read', 'delivery_challan:read', 'purchase_order:read', 'grn:read',
                'audit_log:read', 'report.*',
            ],
        ],

        'read_only' => [
            'label' => 'Read only (auditor)',
            // view and view_any on everything, but no export: exporting is a data-exfiltration
            // path and is granted deliberately (06-rbac §6). The dashboard is included — an
            // auditor is precisely who the read-only overview is for.
            'grants' => ['*:view', 'report.dashboard'],
        ],

        'portal_customer' => [
            'label' => 'Customer portal contact',
            'grants' => [
                'sales_order:view', 'delivery_challan:view', 'sales_invoice:view',
                'artwork:view', 'artwork.approve', 'inquiry.create', 'inquiry:view',
                'test_report:view',
            ],
        ],
    ];

    public function run(): void
    {
        $catalogue = PermissionSeeder::catalogue();
        $permissionIds = DB::table('permissions')->pluck('id', 'name');

        foreach (self::ROLES as $name => $role) {
            DB::table('roles')->upsert(
                [['name' => $name, 'label' => $role['label'], 'is_system' => true]],
                ['name'],
                ['label', 'is_system'],
            );

            $roleId = DB::table('roles')->where('name', $name)->value('id');
            $granted = $this->expand($role['grants'], $catalogue);

            DB::table('role_permissions')->where('role_id', $roleId)->delete();

            $rows = [];

            foreach ($granted as $permission) {
                if (isset($permissionIds[$permission])) {
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionIds[$permission]];
                }
            }

            foreach (array_chunk($rows, 300) as $chunk) {
                DB::table('role_permissions')->insert($chunk);
            }
        }

        // Every user's cached permission set is now potentially wrong.
        User::query()->each(fn (User $user) => $user->forgetPermissionCache());

        $this->command->info(count(self::ROLES).' roles seeded.');
    }

    /**
     * @param  list<string>  $grants
     * @param  list<string>  $catalogue
     * @return list<string>
     */
    private function expand(array $grants, array $catalogue): array
    {
        $granted = [];

        foreach ($grants as $grant) {
            // '*:read' / '*:view' — every resource, restricted actions
            if (str_starts_with($grant, '*:')) {
                $actions = $this->actionsFor(substr($grant, 2));

                foreach ($catalogue as $permission) {
                    [, $action] = explode('.', $permission, 2);

                    if (in_array($action, $actions, true)) {
                        $granted[] = $permission;
                    }
                }

                continue;
            }

            // 'item:read' — one resource, restricted actions
            if (str_contains($grant, ':')) {
                [$resource, $mode] = explode(':', $grant, 2);

                foreach ($this->actionsFor($mode) as $action) {
                    $granted[] = "{$resource}.{$action}";
                }

                continue;
            }

            // 'item.*' — one resource, every action it has
            if (str_ends_with($grant, '.*')) {
                $prefix = substr($grant, 0, -1);

                foreach ($catalogue as $permission) {
                    if (str_starts_with($permission, $prefix)) {
                        $granted[] = $permission;
                    }
                }

                continue;
            }

            $granted[] = $grant;
        }

        return array_values(array_unique(array_intersect($granted, $catalogue)));
    }

    /** @return list<string> */
    private function actionsFor(string $mode): array
    {
        return match ($mode) {
            'read' => self::READ_ACTIONS,
            'view' => ['view_any', 'view'],
            default => [$mode],
        };
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ROLES);
    }
}
