<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What this operator can start right now — released job cards, in J2 order, filtered to the
 * machines their department runs.
 */
class FloorQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $session = $request->attributes->get('device_session');

        $operations = JobCardOperation::query()
            ->runnable()
            // `routingOperation` is read by predecessorsComplete() below, so it is eager
            // loaded here — the filter runs per row and would otherwise lazy load (J2).
            ->with([
                'jobCard:id,number,product_id,colourway,planned_qty,due_date,factory_unit_id',
                'jobCard.product:id,code,name',
                'machine:id,code,name',
                'routingOperation:id,allow_parallel',
            ])
            ->whereHas(
                'jobCard',
                fn (Builder $query) => $query
                    ->where('factory_unit_id', $session->factoryUnitId)
                    ->whereIn('status', [JobCard::RELEASED, JobCard::IN_PRODUCTION, JobCard::ON_HOLD]),
            )
            // A machine shows its own scheduled work *and* the work of its group that
            // planning has not pinned to a machine yet. Filtering on `machine_id` alone left
            // an operator staring at "Nothing to run" while their group's queue was full.
            ->when($request->query('machine_code'), function (Builder $query, string $code): void {
                $machine = DB::table('machines')->where('code', $code)->first(['id', 'machine_group_id']);

                if ($machine === null) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where(fn (Builder $q) => $q
                    ->where('machine_id', $machine->id)
                    ->orWhere(fn (Builder $unpinned) => $unpinned
                        ->whereNull('machine_id')
                        ->where('machine_group_id', $machine->machine_group_id)));
            })
            ->orderBy('scheduled_start')
            ->limit(40)
            ->get()
            ->filter(fn (JobCardOperation $op): bool => $op->predecessorsComplete())
            ->values()
            ->map(fn (JobCardOperation $op): array => [
                'operation_id' => $op->id,
                'sequence_no' => $op->sequence_no,
                'code' => $op->code,
                'name' => $op->name,
                'status' => $op->status,
                'planned_qty' => (float) $op->planned_qty,
                'input_qty' => (float) $op->input_qty,
                'good_qty' => (float) $op->good_qty,
                'waste_qty' => (float) $op->waste_qty,
                'remaining_allowance' => $op->remainingOutputAllowance(),
                'machine' => $op->machine?->code,
                'job_card' => [
                    'id' => $op->jobCard?->id,
                    'number' => $op->jobCard?->number,
                    'product_code' => $op->jobCard?->product?->code,
                    'product_name' => $op->jobCard?->product?->name,
                    'colourway' => $op->jobCard?->colourway,
                    'due_date' => $op->jobCard?->due_date?->toDateString(),
                ],
            ]);

        return response()->json([
            'operator' => $session->employeeName,
            'server_time' => now()->toIso8601String(),
            'operations' => $operations,
        ]);
    }
}
