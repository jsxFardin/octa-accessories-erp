<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\Trip;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owned fleet: multi-drop routes with POD capture at each stop.
 */
class TripController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Trip::query();

        // Data scoping is applied here, not left to the caller: a driver holding only
        // `trip.view_own` sees the trips they are driving and nothing else.
        $user = $request->user();

        if (! $user->hasPermission('trip.view_any')) {
            $driverId = DB::table('drivers')
                ->where('employee_id', $user->employee?->id)
                ->value('id');

            $query->where('driver_id', $driverId);
        }

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status'],
            sortable: ['number', 'trip_date', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Dispatch/Trips/Index', [
            'trips' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }
}
