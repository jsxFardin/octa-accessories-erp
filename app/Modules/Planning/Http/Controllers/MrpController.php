<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Planning\Jobs\RunMrp;
use App\Modules\Planning\Services\MrpRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** BR-24 … BR-26 — the material plan's screen; the computation is `MrpRunner`. */
class MrpController extends Controller
{
    public function __construct(private readonly MrpRunner $runner) {}

    public function index(Request $request): Response
    {
        $runId = $request->integer('run') ?: DB::table('mrp_runs')->max('id');

        return Inertia::render('Planning/Mrp', [
            'runs' => DB::table('mrp_runs')->orderByDesc('id')->limit(20)
                ->get(['id', 'run_at', 'horizon_from', 'horizon_to', 'status', 'shortage_count']),
            'run' => $runId ? DB::table('mrp_runs')->find($runId) : null,
            'requirements' => $runId
                ? DB::table('material_requirements as mr')
                    ->join('items as i', 'i.id', '=', 'mr.item_id')
                    // A made item's product is what a job card is raised for.
                    ->leftJoin('products as p', 'p.item_id', '=', 'i.id')
                    ->where('mr.mrp_run_id', $runId)
                    ->orderByDesc('mr.net_req_qty')
                    ->get([
                        'mr.id', 'i.code as item_code', 'i.name as item_name', 'i.make_or_buy', 'mr.gross_req_qty',
                        'mr.on_hand_qty', 'mr.on_order_qty', 'mr.reserved_qty', 'mr.net_req_qty',
                        'mr.suggested_po_qty', 'mr.suggested_make_qty', 'mr.need_date', 'mr.po_place_by', 'mr.is_shortage',
                        'p.id as product_id',
                    ])
                : [],
        ]);
    }

    /**
     * NFR-3 — the run is opened here and computed on the queue (`RunMrp`). With a sync queue
     * it finishes before the redirect; with a worker the page shows it as running and the
     * planner refreshes. Either way the row exists at once, which is what the list reads.
     */
    public function run(Request $request): RedirectResponse
    {
        $horizonDays = min(180, max(7, (int) $request->input('horizon_days', 60)));

        $runId = $this->runner->open($horizonDays, $request->user()?->id);

        RunMrp::dispatch($runId, $horizonDays);

        $status = (string) DB::table('mrp_runs')->where('id', $runId)->value('status');

        return redirect()
            ->route('mrp.index', ['run' => $runId])
            ->with('success', $status === 'completed'
                ? 'MRP run complete.'
                : 'MRP run queued. It is computed by the worker; refresh in a moment.');
    }
}
