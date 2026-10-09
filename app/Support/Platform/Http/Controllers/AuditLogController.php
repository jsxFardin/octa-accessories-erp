<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Http\ListsResources;
use App\Support\Text\Plain;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who changed what, and when — readable by the person who has to answer that question.
 *
 * The log used to filter by event only and print "SalesOrder #12" with a list of column
 * names. It now filters by person, record type and day, says "Sales order #12" with a link
 * to the record, and shows each change as old → new.
 */
class AuditLogController extends Controller
{
    use ListsResources;

    /**
     * Where a record's page is, by the model's short name. Index-only resources (tools,
     * users) link to their list.
     *
     * @var array<string, string>
     */
    private const PATHS = [
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
    private const LISTS = ['Tool' => '/tools', 'User' => '/admin/users', 'Role' => '/admin/roles'];

    public function index(Request $request): Response
    {
        $query = AuditLog::query()->with('user:id,name');

        $this->applyListing(
            $query,
            $request,
            searchable: ['auditable_type', 'event', 'auditable_id'],
            filters: ['event' => 'event', 'type' => 'auditable_type', 'user' => 'user_id'],
            sortable: ['created_at', 'event'],
            defaultSort: '-id',
        );

        // A day range, inclusive; "what changed on Tuesday" is the question the log answers.
        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $types = AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type');

        return Inertia::render('Admin/AuditLog', [
            'entries' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'user' => $log->user?->name,
                    'event' => $log->event,
                    'record' => self::label($log->auditable_type),
                    'auditable_id' => $log->auditable_id,
                    'href' => self::href($log->auditable_type, (int) $log->auditable_id),
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at,
                ],
            ),
            'filters' => $this->listingFilters($request, ['event', 'type', 'user', 'from', 'to']),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'types' => $types->map(fn (string $type): array => ['value' => $type, 'label' => ucfirst(self::label($type))])->values(),
        ]);
    }

    /** "SalesOrder" or "sales_orders" → "sales order". */
    public static function label(string $type): string
    {
        $key = Str::snake(class_basename($type));

        return Plain::record(Str::singular($key));
    }

    public static function href(string $type, int $id): ?string
    {
        $name = class_basename($type);

        // A table name recorded by `recordTable` ("price_lists") maps the same way.
        if (! class_exists($type)) {
            $name = Str::studly(Str::singular($name));
        }

        if (isset(self::PATHS[$name])) {
            return self::PATHS[$name].'/'.$id;
        }

        return self::LISTS[$name] ?? null;
    }
}
