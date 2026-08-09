<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop-floor terminal. FloorLayout: large targets, high contrast, four buttons, Bangla by
 * default — because a machine operator is wearing gloves under a glare, not sitting at a desk
 * (08-architecture §4).
 */
class FloorTerminalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Floor/Login', [
            'machines' => DB::table('machines')->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function queue(Request $request): Response
    {
        return Inertia::render('Floor/Queue', [
            'machineCode' => $request->query('machine'),
        ]);
    }

    public function operation(JobCardOperation $operation): Response
    {
        $operation->load(['jobCard.product', 'jobCard.artworkVersion.artwork', 'machine']);

        return Inertia::render('Floor/Operation', [
            'operation' => [
                ...$operation->only([
                    'id', 'sequence_no', 'code', 'name', 'planned_qty', 'input_qty',
                    'good_qty', 'waste_qty', 'status', 'started_at',
                ]),
                'machine' => $operation->machine?->only(['id', 'code', 'name']),
                'remaining_allowance' => $operation->remainingOutputAllowance(),
                'job_card' => [
                    'id' => $operation->jobCard?->id,
                    'number' => $operation->jobCard?->number,
                    'product_code' => $operation->jobCard?->product?->code,
                    'colourway' => $operation->jobCard?->colourway,
                    'planned_qty' => $operation->jobCard?->planned_qty,
                    // Gate 1, visible on the floor: the operator can see which artwork
                    // version this run is bound to.
                    'artwork' => $operation->jobCard?->artworkVersion?->artwork?->code
                        .' v'.$operation->jobCard?->artworkVersion?->version_no,
                ],
            ],
            'downtimeReasons' => DB::table('downtime_reasons')->orderBy('name')
                ->get(['id', 'code', 'name', 'category']),
            'shifts' => DB::table('shifts')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }
}
