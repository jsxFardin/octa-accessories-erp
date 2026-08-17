<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The four buttons (07-api-contracts §2): start, log, finish, downtime.
 *
 * Every write is idempotent on `Idempotency-Key` and ordered by the client's `occurred_at`,
 * because the terminal replays a four-hour offline queue when the network returns and a
 * double-posted shift output is silent data corruption.
 */
class OperationEventController extends Controller
{
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(private readonly JobCardStateMachine $jobCards) {}

    public function start(Request $request, JobCardOperation $operation): JsonResponse
    {
        return $this->idempotent($request, function () use ($request, $operation): array {
            // J2 — an operation cannot start before its predecessor is done, unless the
            // routing marks it parallel.
            if (! $operation->predecessorsComplete()) {
                abort(422, 'J2: an earlier operation on this job card is still open.');
            }

            $occurredAt = $this->occurredAt($request);

            DB::transaction(function () use ($operation, $occurredAt, $request): void {
                $operation->forceFill([
                    'status' => JobCardOperation::IN_PROGRESS,
                    'started_at' => $operation->started_at ?? $occurredAt,
                    'machine_id' => $request->integer('machine_id') ?: $operation->machine_id,
                ])->save();

                $jobCard = $operation->jobCard;

                if ($jobCard !== null && $jobCard->status === JobCard::RELEASED) {
                    $this->jobCards->transition($jobCard, JobCard::IN_PRODUCTION);
                }
            });

            return ['operation_id' => $operation->id, 'status' => $operation->status];
        });
    }

    /**
     * Shift-level output. J3 is enforced by the database
     * (`good_qty + waste_qty <= input_qty + epsilon`); this rejects it earlier with a message
     * an operator can act on.
     */
    public function log(Request $request, JobCardOperation $operation): JsonResponse
    {
        $data = $request->validate([
            'good_qty' => ['required', 'numeric', 'min:0'],
            'waste_qty' => ['numeric', 'min:0'],
            'input_qty' => ['nullable', 'numeric', 'min:0'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'input_lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'output_lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->idempotent($request, function () use ($request, $operation, $data): array {
            $session = $request->attributes->get('device_session');
            $occurredAt = $this->occurredAt($request);

            $good = (float) $data['good_qty'];
            $waste = (float) ($data['waste_qty'] ?? 0);
            $addedInput = (float) ($data['input_qty'] ?? 0);

            DB::transaction(function () use ($operation, $data, $good, $waste, $addedInput, $occurredAt, $session): void {
                /** @var JobCardOperation $locked */
                $locked = JobCardOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

                $newInput = (float) $locked->input_qty + $addedInput;
                $newGood = (float) $locked->good_qty + $good;
                $newWaste = (float) $locked->waste_qty + $waste;

                if ($newGood + $newWaste > $newInput + 0.000001) {
                    abort(422, sprintf(
                        'J3: output %.3f exceeds the %.3f handed to this operation. Record the input first.',
                        $newGood + $newWaste,
                        $newInput,
                    ));
                }

                DB::table('operation_logs')->insert([
                    'job_card_operation_id' => $locked->id,
                    'machine_id' => $data['machine_id'] ?? $locked->machine_id,
                    'operator_id' => $session->employeeId,
                    'shift_id' => $data['shift_id'] ?? null,
                    'started_at' => $locked->started_at ?? $occurredAt,
                    'ended_at' => $occurredAt,
                    // No input_qty column here — per-shift input accumulates on the operation
                    // row (J3 is checked against that running total, above).
                    'good_qty' => $good,
                    'waste_qty' => $waste,
                    'input_lot_id' => $data['input_lot_id'] ?? null,
                    'output_lot_id' => $data['output_lot_id'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'created_at' => now(),
                ]);

                $locked->forceFill([
                    'input_qty' => $newInput,
                    'good_qty' => $newGood,
                    'waste_qty' => $newWaste,
                ])->save();

                $jobCard = $locked->jobCard;

                if ($jobCard !== null) {
                    // The job card's running totals are maintained in the same transaction as
                    // the event that moves them, so they reconcile against the logs.
                    $jobCard->forceFill([
                        'good_qty' => (float) $jobCard->good_qty + $good,
                        'waste_qty' => (float) $jobCard->waste_qty + $waste,
                        'produced_qty' => (float) $jobCard->produced_qty + $good + $waste,
                    ])->save();

                    // P0-2 — the order line's produced total moves with the *final* operation's
                    // good output, in this same transaction. Only the last operation counts:
                    // 50,000 labels woven, cut and folded is 50,000 produced, not 150,000.
                    // Atomic increment, not read-modify-write — two terminals logging the same
                    // final operation must not lose an update. The S2 cancellation guard and
                    // the job-card "remaining to cover" filter read this column.
                    $finalSequence = (int) JobCardOperation::query()
                        ->where('job_card_id', $locked->job_card_id)
                        ->max('sequence_no');

                    if ($good > 0
                        && $locked->sequence_no === $finalSequence
                        && $jobCard->sales_order_line_id !== null) {
                        DB::table('sales_order_lines')
                            ->where('id', $jobCard->sales_order_line_id)
                            ->increment('produced_qty', $good);
                    }
                }
            });

            $operation->refresh();

            return [
                'operation_id' => $operation->id,
                'good_qty' => (float) $operation->good_qty,
                'waste_qty' => (float) $operation->waste_qty,
                'input_qty' => (float) $operation->input_qty,
            ];
        });
    }

    public function finish(Request $request, JobCardOperation $operation): JsonResponse
    {
        return $this->idempotent($request, function () use ($request, $operation): array {
            $occurredAt = $this->occurredAt($request);

            DB::transaction(function () use ($operation, $occurredAt): void {
                $operation->forceFill([
                    'status' => JobCardOperation::COMPLETED,
                    'finished_at' => $occurredAt,
                    'actual_minutes' => $operation->started_at
                        ? round($operation->started_at->diffInMinutes($occurredAt), 2)
                        : $operation->actual_minutes,
                ])->save();

                // The next operation joins the queue as soon as this one closes — the floor
                // should not need the planner to advance it.
                JobCardOperation::query()
                    ->where('job_card_id', $operation->job_card_id)
                    ->where('sequence_no', '>', $operation->sequence_no)
                    ->where('status', JobCardOperation::PENDING)
                    ->orderBy('sequence_no')
                    ->limit(1)
                    ->update(['status' => JobCardOperation::READY]);

                $jobCard = $operation->jobCard;

                $stillOpen = JobCardOperation::query()
                    ->where('job_card_id', $operation->job_card_id)
                    ->whereNotIn('status', [JobCardOperation::COMPLETED, JobCardOperation::SKIPPED, JobCardOperation::CANCELLED])
                    ->exists();

                if (! $stillOpen && $jobCard !== null && $jobCard->status === JobCard::IN_PRODUCTION) {
                    $this->jobCards->transition($jobCard, JobCard::QC_PENDING);
                }
            });

            return ['operation_id' => $operation->id, 'status' => JobCardOperation::COMPLETED];
        });
    }

    public function downtime(Request $request, JobCardOperation $operation): JsonResponse
    {
        $data = $request->validate([
            'downtime_reason_id' => ['required', 'integer', 'exists:downtime_reasons,id'],
            'minutes' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->idempotent($request, function () use ($request, $operation, $data): array {
            $occurredAt = $this->occurredAt($request);
            $session = $request->attributes->get('device_session');

            // Downtime is attributed to the machine (it feeds OEE), waste to the job card
            // (it feeds cost variance). Two tables, deliberately.
            $id = DB::table('downtime_logs')->insertGetId([
                'machine_id' => $operation->machine_id,
                'job_card_operation_id' => $operation->id,
                'downtime_reason_id' => $data['downtime_reason_id'],
                'started_at' => $occurredAt->subMinutes((int) $data['minutes']),
                'ended_at' => $occurredAt,
                'minutes' => $data['minutes'],
                'logged_by' => $session->employeeId,
                'remarks' => $data['remarks'] ?? null,
                'created_at' => now(),
            ]);

            return ['downtime_log_id' => $id];
        });
    }

    /**
     * Idempotency: the same key replays the stored response rather than repeating the write.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function idempotent(Request $request, callable $work): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return response()->json([
                'message' => 'Idempotency-Key header is required on shop-floor writes.',
            ], 428);
        }

        $cacheKey = 'idempotency:'.hash('sha256', $key);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return response()->json([...$cached, 'replayed' => true]);
        }

        $result = $work();

        Cache::put($cacheKey, $result, now()->addHours(self::IDEMPOTENCY_TTL_HOURS));

        return response()->json($result);
    }

    /**
     * The client stamps when the event happened; the server records when it heard about it.
     * A queue drained after a four-hour outage must order by the former.
     */
    private function occurredAt(Request $request): CarbonImmutable
    {
        $occurredAt = $request->input('occurred_at');

        if ($occurredAt === null) {
            return CarbonImmutable::now();
        }

        $parsed = CarbonImmutable::parse($occurredAt);

        // A clock ahead of the server is a device with a wrong clock, not a time traveller.
        return $parsed->isFuture() ? CarbonImmutable::now() : $parsed;
    }
}
