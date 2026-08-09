<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\PackingList;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D1 — a carton's contents must all come from lots that passed final QC.
 */
class PackingListController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = PackingList::query()->with([]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status'],
            sortable: ['number', 'packed_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Dispatch/PackingLists/Index', [
            'packing_lists' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }
}
