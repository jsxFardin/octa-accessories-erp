<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shortages raised by an MRP run arrive here as requisitions (BR-24).
 */
class PurchaseRequisitionController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = PurchaseRequisition::query()->with([]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status'],
            sortable: ['number', 'required_date', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/Requisitions/Index', [
            'purchase_requisitions' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }
}
