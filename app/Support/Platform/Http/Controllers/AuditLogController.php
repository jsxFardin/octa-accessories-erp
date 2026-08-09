<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLog;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = AuditLog::query()->with('user:id,name');

        $this->applyListing(
            $query,
            $request,
            searchable: ['auditable_type', 'event'],
            filters: ['event' => 'event', 'type' => 'auditable_type', 'user' => 'user_id'],
            sortable: ['created_at', 'event'],
            defaultSort: '-id',
        );

        return Inertia::render('Admin/AuditLog', [
            'entries' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'user' => $log->user?->name,
                    'event' => $log->event,
                    // The class name is noise on screen; the model is what a reader wants.
                    'auditable' => class_basename($log->auditable_type),
                    'auditable_id' => $log->auditable_id,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at,
                ],
            ),
            'filters' => $this->listingFilters($request, ['event', 'type', 'user']),
        ]);
    }
}
