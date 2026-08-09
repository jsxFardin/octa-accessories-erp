<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Routing;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BR-8 — the routing is where the wastage and make-ready defaults live, per operation.
 */
class RoutingController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Routing::query()->with(['operations']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name'],
            filters: ['product_type' => 'product_type'],
            sortable: ['code', 'name', 'product_type'],
            defaultSort: 'code',
        );

        return Inertia::render('Product/Routings/Index', [
            'routings' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['product_type']),
        ]);
    }
}
