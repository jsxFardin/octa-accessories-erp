<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\SalesInvoice;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An AR subledger, not a general ledger — enough for ageing, credit control and export.
 */
class SalesInvoiceController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = SalesInvoice::query()->with(['customer:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'mushak_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'invoice_date', 'due_date', 'status', 'total'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/Invoices/Index', [
            'sales_invoices' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
        ]);
    }
}
