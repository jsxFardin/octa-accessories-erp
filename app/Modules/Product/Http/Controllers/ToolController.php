<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Tool;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BR-13 — plates, screens, dies and patterns, with the impressions they have left.
 */
class ToolController extends Controller
{
    use ListsResources;

    /** The kinds and statuses the table itself allows (`tools_kind_chk`, `tools_status_chk`). */
    public const KINDS = ['flexo_plate', 'screen', 'offset_plate', 'cutting_die', 'embossing_die', 'cad_pattern', 'mould'];

    public const STATUSES = ['in_production', 'available', 'in_use', 'worn', 'scrapped'];

    /** What a person may set by hand. `in_use` is the floor's to set, when a job takes the tool. */
    private const SETTABLE = ['in_production', 'available', 'worn'];

    public function index(Request $request): Response
    {
        $query = Tool::query()->with(['spec.product', 'ownerCustomer:id,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'location'],
            filters: ['kind' => 'kind', 'status' => 'status'],
            sortable: ['code', 'kind', 'status'],
            defaultSort: 'code',
        );

        return Inertia::render('Product/Tools/Index', [
            'tools' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Tool $tool): array => [
                    ...$tool->only(['id', 'code', 'kind', 'product_spec_id', 'colour_index', 'location',
                        'made_on', 'cost', 'life_impressions', 'used_impressions', 'status',
                        'cavity_count', 'owner_customer_id', 'item_id']),
                    'owner' => $tool->ownerCustomer?->name,
                    'product' => $tool->spec?->product?->only(['id', 'code', 'name']),
                    'spec_version' => $tool->spec?->version_no,
                ],
            ),
            'filters' => $this->listingFilters($request, ['kind', 'status']),
            'kinds' => self::KINDS,
            'statuses' => self::STATUSES,
            /*
             * The specs a tool can be made for. A plate is cut for one geometry, so the tool
             * belongs to a spec version rather than to the product; current and draft versions
             * are offered, a superseded one is not — nothing new is made for it.
             */
            'specs' => DB::table('product_specs as ps')
                ->join('products as p', 'p.id', '=', 'ps.product_id')
                ->join('items as pi', 'pi.id', '=', 'p.item_id')
                ->whereIn('ps.status', ['current', 'draft'])
                ->orderBy('pi.code')->orderByDesc('ps.version_no')
                ->get(['ps.id', 'ps.version_no', 'ps.status', 'ps.colours', 'pi.code', 'pi.name']),
            'customers' => \App\Modules\MasterData\Models\Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'toolItems' => \App\Modules\MasterData\Models\Item::query()->ofType('tool')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Register a plate, screen, die or pattern.
     *
     * The list was read-only: tools existed only if someone had put them in the database by
     * hand, and the release gate (BR-13) checks a job's tools against this very table.
     */
    public function store(Request $request): RedirectResponse
    {
        $tool = Tool::query()->create([...$this->validated($request), 'used_impressions' => 0]);

        return back()->with('success', "Tool {$tool->code} registered.");
    }

    public function update(Request $request, Tool $tool): RedirectResponse
    {
        if ($tool->status === 'scrapped') {
            return back()->with('error', "{$tool->code} has been retired and can no longer be changed.");
        }

        $data = $this->validated($request, $tool);

        // A tool on a machine stays on it: its status is the floor's until the job lets go.
        if ($tool->status === 'in_use') {
            unset($data['status']);
        }

        $tool->update($data);

        return back()->with('success', "Tool {$tool->code} updated.");
    }

    /**
     * Take a tool out of service for good — worn out, damaged or lost.
     *
     * Kept, not deleted: the jobs it ran still point at it.
     */
    public function retire(Tool $tool): RedirectResponse
    {
        if ($tool->status === 'scrapped') {
            return back()->with('error', "{$tool->code} is already retired.");
        }

        if ($tool->status === 'in_use') {
            return back()->with('error', "{$tool->code} is on a machine. Finish or hold the job using it before retiring it.");
        }

        $tool->update(['status' => 'scrapped']);

        return back()->with('success', "Tool {$tool->code} retired. It can no longer be chosen for a job.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Tool $tool = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('tools', 'code')->ignore($tool?->id)],
            'kind' => ['required', Rule::in(self::KINDS)],
            'product_spec_id' => ['nullable', 'integer', 'exists:product_specs,id'],
            'colour_index' => ['nullable', 'integer', 'min:1', 'max:20'],
            // A mould is defined by how many pieces one shot gives.
            'cavity_count' => ['nullable', 'integer', 'min:1', Rule::requiredIf($request->input('kind') === 'mould')],
            'owner_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'location' => ['nullable', 'string', 'max:80'],
            'made_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            // Not below what it has already run: a life shorter than its use is a typo.
            'life_impressions' => ['nullable', 'integer', 'min:'.max(1, $tool === null ? 0 : $tool->used_impressions)],
            'status' => ['required', Rule::in(self::SETTABLE)],
        ], [
            'code.unique' => 'Another tool already has this code.',
            'cavity_count.required' => 'A mould needs its cavity count.',
            'life_impressions.min' => $tool !== null && $tool->used_impressions > 0
                ? "This tool has already run {$tool->used_impressions} impressions; its life cannot be less than that."
                : 'Enter the number of impressions this tool is good for.',
        ]);

        $data['cost'] ??= 0;

        return $data;
    }
}
