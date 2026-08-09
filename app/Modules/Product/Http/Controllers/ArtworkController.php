<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Employee;
use App\Modules\Product\Models\Artwork;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\States\ArtworkVersionStateMachine;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The artwork workspace — Gate 1's user interface.
 */
class ArtworkController extends Controller
{
    use ListsResources;

    public function __construct(private readonly ArtworkVersionStateMachine $states) {}

    public function index(Request $request): Response
    {
        $query = Artwork::query()->with(['product.customer:id,name', 'versions', 'designer:id,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'title'],
            filters: ['product' => 'product_id'],
            sortable: ['code', 'title'],
            defaultSort: '-id',
        );

        // Filtering by "what is waiting on the customer" is the question this screen is
        // opened to answer, so it is a filter rather than a report.
        if ($request->query('state') === 'awaiting_approval') {
            $query->whereHas('versions', fn ($q) => $q->where('status', ArtworkVersion::SUBMITTED));
        }

        if ($request->query('state') === 'unapproved') {
            $query->whereDoesntHave('versions', fn ($q) => $q->where('status', ArtworkVersion::APPROVED));
        }

        return Inertia::render('Product/Artworks/Index', [
            'artworks' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Artwork $artwork): array => [
                    'id' => $artwork->id,
                    'code' => $artwork->code,
                    'title' => $artwork->title,
                    'product' => $artwork->product?->only(['id', 'code', 'name']),
                    'customer' => $artwork->product?->customer?->name,
                    'designer' => $artwork->designer?->name,
                    'version_count' => $artwork->versions->count(),
                    'latest_version' => $artwork->versions->first()?->only(['id', 'version_no', 'status']),
                    'approved_version' => $artwork->versions->firstWhere('status', ArtworkVersion::APPROVED)
                        ?->only(['id', 'version_no', 'approved_at', 'customer_ref']),
                ],
            ),
            'filters' => $this->listingFilters($request, ['product', 'state']),
            // The create dialog lives on this screen; artwork has no page of its own until a
            // product owns it.
            'products' => DB::table('products as p')
                ->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
                ->where('p.status', '!=', 'obsolete')
                ->orderBy('p.code')
                ->get(['p.id', 'p.code', 'p.name', 'c.name as customer_name']),
            'designers' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Artwork $artwork): Response
    {
        $artwork->load(['product.customer', 'versions.approver:id,name', 'versions.creator:id,name', 'designer:id,name']);

        return Inertia::render('Product/Artworks/Show', [
            'artwork' => [
                'id' => $artwork->id,
                'code' => $artwork->code,
                'title' => $artwork->title,
                'designer' => $artwork->designer?->name,
                'designer_id' => $artwork->designer_id,
                'product' => $artwork->product?->only(['id', 'code', 'name', 'product_type']),
                'customer' => $artwork->product?->customer?->only(['id', 'code', 'name']),
            ],
            'versions' => $artwork->versions->map(fn (ArtworkVersion $version): array => [
                ...$version->only([
                    'id', 'version_no', 'status', 'file_path', 'file_format', 'preview_path',
                    'checksum_sha256', 'submitted_at', 'approved_at', 'customer_ref',
                    'rejection_reason', 'created_at',
                ]),
                'approved_by' => $version->approver?->name,
                'created_by' => $version->creator?->name,
                // What the state machine will actually allow this user to do, so the UI never
                // offers a button that throws.
                'available_transitions' => $this->states->available($version),
                'referenced_by_production' => $version->isReferencedByProduction(),
            ]),
            'nextVersionNo' => $artwork->nextVersionNo(),
            'designers' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'codeLocked' => $artwork->versions->isNotEmpty(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'code' => ['required', 'string', 'max:40', 'unique:artworks,code'],
            'title' => ['required', 'string', 'max:180'],
            'designer_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $artwork = Artwork::query()->create($data);

        return redirect()
            ->route('artworks.show', $artwork)
            ->with('success', "Artwork {$artwork->code} created. Upload version 1 to begin.");
    }

    /**
     * The artwork record itself — its title, its designer, and its code while nothing has been
     * drawn against it yet. Versions are immutable and are not touched here (A1).
     */
    public function update(Request $request, Artwork $artwork): RedirectResponse
    {
        $hasVersions = $artwork->versions()->exists();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'designer_id' => ['nullable', 'integer', 'exists:employees,id'],
            // A code printed on an approved version is a reference other people already hold.
            'code' => $hasVersions
                ? ['nullable']
                : ['required', 'string', 'max:40', Rule::unique('artworks', 'code')->ignore($artwork->id)],
        ]);

        if ($hasVersions) {
            unset($data['code']);
        }

        $artwork->update($data);

        return back()->with('success', "Artwork {$artwork->code} updated.");
    }
}
