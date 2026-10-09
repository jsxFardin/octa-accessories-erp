<?php

declare(strict_types=1);

namespace App\Support\Text;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A record reference ("App\Modules\Sales\Models\SalesOrder", 12) as a person reads it:
 * "Sales order SO-00012", with the page it lives on.
 *
 * The audit log and the stock ledger both store a class name and an id. Printed raw, a lot's
 * movement history read "Grn #7" and "JobCard #41" — a PHP class name is not a source a store
 * keeper recognises, and nothing on the page led to the document. One place turns the pair
 * into a label and a link so the two screens cannot disagree about where a record lives.
 */
final class RecordLink
{
    /**
     * Where a record's page is, by the model's short name. Index-only resources (tools,
     * users) link to their list.
     *
     * @var array<string, string>
     */
    public const PATHS = [
        'SalesOrder' => '/sales-orders', 'Quotation' => '/quotations', 'Inquiry' => '/inquiries',
        'Customer' => '/customers', 'Supplier' => '/suppliers', 'Item' => '/items', 'Product' => '/products',
        'Machine' => '/machines', 'Routing' => '/routings', 'PriceList' => '/price-lists',
        'PurchaseRequisition' => '/purchase-requisitions', 'SupplierRfq' => '/rfqs', 'PurchaseOrder' => '/purchase-orders',
        'Grn' => '/grns', 'LetterOfCredit' => '/letters-of-credit', 'ImportShipment' => '/import-shipments',
        'Expense' => '/expenses', 'JobCard' => '/job-cards', 'SalesInvoice' => '/invoices', 'CreditNote' => '/credit-notes',
        'SalesReturn' => '/sales-returns', 'SupplierBill' => '/supplier-bills', 'Payment' => '/payments',
        'Receipt' => '/receipts', 'Refund' => '/refunds', 'PackingList' => '/packing-lists',
        'DeliveryChallan' => '/delivery-challans', 'Trip' => '/trips', 'StockLot' => '/lots',
        'MaterialIssue' => '/material-issues', 'StockTransfer' => '/stock-transfers',
        'StockAdjustment' => '/stock-adjustments', 'PhysicalCount' => '/physical-counts',
        'QcInspection' => '/qc-inspections', 'Ncr' => '/ncrs', 'TestReport' => '/lab/reports',
        'Artwork' => '/artworks', 'Bom' => '/boms', 'ProductionPlan' => '/planning',
    ];

    /** @var array<string, string> lists without a page per row */
    public const LISTS = ['Tool' => '/tools', 'User' => '/admin/users', 'Role' => '/admin/roles'];

    /** "App\…\SalesOrder" or "sales_orders" → "sales order". */
    public static function label(string $type): string
    {
        return Plain::record(Str::singular(Str::snake(class_basename($type))));
    }

    public static function href(string $type, int $id): ?string
    {
        $name = self::shortName($type);

        if (isset(self::PATHS[$name])) {
            return self::PATHS[$name].'/'.$id;
        }

        return self::LISTS[$name] ?? null;
    }

    /**
     * The document numbers behind a set of references, one query per type: ledger rows name
     * "Goods receipt GRN-00007", not "Goods receipt #7".
     *
     * @param  iterable<object|array{source_type: ?string, source_id: ?int}>  $refs
     * @return array<string, array<int, string>> type => id => number
     */
    public static function numbers(iterable $refs): array
    {
        $ids = [];

        foreach ($refs as $ref) {
            $type = is_array($ref) ? ($ref['source_type'] ?? null) : ($ref->source_type ?? null);
            $id = is_array($ref) ? ($ref['source_id'] ?? null) : ($ref->source_id ?? null);

            if ($type !== null && $id !== null) {
                $ids[$type][] = (int) $id;
            }
        }

        $numbers = [];

        foreach ($ids as $type => $list) {
            $table = self::table($type);

            if ($table === null) {
                continue;
            }

            $column = Schema::hasColumn($table, 'number') ? 'number'
                : (Schema::hasColumn($table, 'lot_no') ? 'lot_no' : (Schema::hasColumn($table, 'code') ? 'code' : null));

            if ($column === null) {
                continue;
            }

            $numbers[$type] = DB::table($table)->whereIn('id', array_unique($list))
                ->pluck($column, 'id')->map(fn ($value): string => (string) $value)->all();
        }

        return $numbers;
    }

    /**
     * One reference as the page shows it.
     *
     * @param  array<string, array<int, string>>  $numbers  from numbers()
     * @return array{label: string, href: ?string}|null
     */
    public static function describe(?string $type, ?int $id, array $numbers = []): ?array
    {
        if ($type === null || $id === null) {
            return null;
        }

        $number = $numbers[$type][$id] ?? null;

        return [
            'label' => ucfirst(self::label($type)).' '.($number ?? '#'.$id),
            'href' => self::href($type, $id),
        ];
    }

    private static function shortName(string $type): string
    {
        $name = class_basename($type);

        // A table name recorded by `recordTable` ("price_lists") maps the same way.
        return class_exists($type) ? $name : Str::studly(Str::singular($name));
    }

    private static function table(string $type): ?string
    {
        if (class_exists($type) && is_subclass_of($type, \Illuminate\Database\Eloquent\Model::class)) {
            return (new $type)->getTable();
        }

        return Schema::hasTable($type) ? $type : null;
    }
}
