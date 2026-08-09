<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Receipt;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receipts allocate against invoices; the unallocated remainder is the customer's advance.
 */
class ReceiptController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Receipt::query()->with(['customer:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'reference_no'],
            filters: ['customer' => 'customer_id'],
            sortable: ['number', 'receipt_date', 'amount'],
            defaultSort: '-id',
        );

        return Inertia::render('Finance/Receipts/Index', [
            'receipts' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['customer']),
        ]);
    }
}
