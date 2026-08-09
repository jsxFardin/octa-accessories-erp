<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods leave the factory on a challan; the invoice follows it (05-workflows §10).
 */
class DeliveryChallanController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = DeliveryChallan::query()->with(['customer:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'mode' => 'mode'],
            sortable: ['number', 'challan_date', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Dispatch/Challans/Index', [
            'delivery_challans' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'mode']),
        ]);
    }
}
