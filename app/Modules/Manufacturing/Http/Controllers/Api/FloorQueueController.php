<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->with(['jobCard:id,number,product_id,colourway,planned_qty,due_date,factory_unit_id', 'jobCard.product:id,code,name', 'machine:id,code,name'])
            ->whereHas(
                'jobCard',
                fn (Builder $query) => $query
                    ->where('factory_unit_id', $session->factoryUnitId)
                    ->whereIn('status', [JobCard::RELEASED, JobCard::IN_PRODUCTION, JobCard::ON_HOLD]),
            )
            ->when($request->query('machine_code'), fn ($q, $code) => $q->whereHas('machine', fn ($m) => $m->where('code', $code)))
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
