<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Tool;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BR-13 — plates, screens, dies and patterns, with the impressions they have left.
 */
class ToolController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Tool::query()->with(['spec.product']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code'],
            filters: ['kind' => 'kind', 'status' => 'status'],
            sortable: ['code', 'kind', 'status'],
            defaultSort: 'code',
        );

        return Inertia::render('Product/Tools/Index', [
            'tools' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['kind', 'status']),
        ]);
    }
}
