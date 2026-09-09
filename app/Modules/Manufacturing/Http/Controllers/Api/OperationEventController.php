<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\OperationBookingService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\States\StateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    public function __construct(
        private readonly JobCardStateMachine $jobCards,
        private readonly OperationBookingService $bookings,
    ) {}

    public function start(Request $request, JobCardOperation $operation): JsonResponse
    {
        $this->assertWithinUnit($request, $operation);

        return $this->idempotent($request, $operation, 'start', function () use ($request, $operation): array {
            // J2 — an operation cannot start before its predecessor is done, unless the
            // routing marks it parallel.
            $blocker = $operation->blockingPredecessor();

            if ($blocker !== null) {
                abort(422, sprintf(
                    'J2: %s cannot start because %s (step %d) is still %s. Finish it first, or ask a planner to skip it.',
                    $operation->name,
                    $blocker->name,
                    $blocker->sequence_no,
                    str_replace('_', ' ', $blocker->status),
                ));
            }

            // QC1 — the `QC` badge on the preceding row is a gate, not a note.
            if (! $operation->qcClearedUpstream()) {
                abort(422, 'QC1: an earlier operation needs an accepted inspection before this one may start.');
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
        $this->assertWithinUnit($request, $operation);

        $data = $request->validate([
            'good_qty' => ['required', 'numeric', 'min:0'],
            'waste_qty' => ['numeric', 'min:0'],
            'input_qty' => ['nullable', 'numeric', 'min:0'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'input_lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'output_lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'input_override_reason' => ['nullable', 'string', 'max:255'],
            /*
             * G4 — waste with a cause, not a bare number.
             *
             * Waste arrived as a quantity and nothing else, and `waste_logs` — the table built
             * to hold the cause, the item, the lot and the value — was read by the job card
             * and written by nothing at all, so its panel was permanently empty. "Wastage % per
             * machine tracked and trending down" cannot be acted on without knowing whether a
             * loom is losing metres to setup, to shade or to a weave defect, because each one
             * is a different fix.
             *
             * The vocabulary is the table's own CHECK constraint, checked here so a bad value
             * is a refusal rather than a 500 on the insert. Whether it is *required* is a
             * question about the quantity, not the field, and is asked below: `required_with`
             * fires on a present-but-zero `waste_qty`, which every ordinary booking sends.
             */
            'waste_type' => ['nullable', Rule::in(OperationBookingService::WASTE_TYPES)],
        ]);

        // The reason is owed when there is waste to explain, not whenever the key is present.
        if ((float) ($data['waste_qty'] ?? 0) > 0 && blank($data['waste_type'] ?? null)) {
            abort(422, 'Waste needs a reason. Pick what the waste was: setup, shade, a print or weave defect, cutting, edge trim, damage, expiry, or other.');
        }

        return $this->idempotent($request, $operation, 'log', function () use ($request, $operation, $data): array {
            $session = $request->attributes->get('device_session');

            // The rules live in `OperationBookingService` because the desk can book too, and a
            // guard that exists on one path and not the other is worse than no guard: it makes
            // the weaker door the one people learn to use.
            $this->bookings->book(
                $operation,
                $data,
                $this->occurredAt($request),
                operatorId: $session->employeeId,
            );

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
        $this->assertWithinUnit($request, $operation);

        return $this->idempotent($request, $operation, 'finish', function () use ($request, $operation): array {
            $occurredAt = $this->occurredAt($request);

            // An operation that closes with nothing booked reports a machine that ran a shift
            // and made nothing: BR-27 utilisation is understated for good, and the operation
            // that follows inherits an input of zero. Closing empty is a real thing — a job
            // pulled off the machine — so it is allowed, but only when said out loud.
            $booked = (float) $operation->good_qty + (float) $operation->waste_qty;

            if ($booked <= 0 && blank($request->input('no_output_reason'))) {
                abort(422, 'J3: nothing has been booked against this operation. Record the output, or finish with a reason.');
            }

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
                    // The operator finished their operation; the card moving to QC is the
                    // system's consequence, not their action. `qc_pending` demands
                    // `job_card.update`, which no operator holds, so charging them for it
                    // made the last operation of every job unfinishable.
                    StateMachine::asSystem(fn () => $this->jobCards->transition($jobCard, JobCard::QC_PENDING));
                }
            });

            return ['operation_id' => $operation->id, 'status' => JobCardOperation::COMPLETED];
        });
    }

    public function downtime(Request $request, JobCardOperation $operation): JsonResponse
    {
        $this->assertWithinUnit($request, $operation);

        $data = $request->validate([
            'downtime_reason_id' => ['required', 'integer', 'exists:downtime_reasons,id'],
            'minutes' => ['required', 'numeric', 'gt:0'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->idempotent($request, $operation, 'downtime', function () use ($request, $operation, $data): array {
            $occurredAt = $this->occurredAt($request);
            $session = $request->attributes->get('device_session');

            // Downtime is attributed to the machine (it feeds OEE), waste to the job card
            // (it feeds cost variance). Two tables, deliberately.
            //
            // `machine_id` is NOT NULL on this table. A step that has never been started and
            // was never given a machine by the planner has none, and the insert died on the
            // constraint — a 500 the offline queue files as a permanent reject, so the stop
            // was lost rather than reported. Refuse it in words instead.
            $machineId = $data['machine_id'] ?? $operation->machine_id;

            if ($machineId === null) {
                abort(422, 'This step is not on a machine yet, and downtime belongs to a machine. Start the operation first, or ask a planner to schedule it.');
            }

            $id = DB::table('downtime_logs')->insertGetId([
                'machine_id' => $machineId,
                'job_card_operation_id' => $operation->id,
                'downtime_reason_id' => $data['downtime_reason_id'],
                'shift_id' => $data['shift_id'] ?? null,
                'started_at' => $occurredAt->subMinutes((int) $data['minutes']),
                'ended_at' => $occurredAt,
                'minutes' => $data['minutes'],
                // The column is `reported_by`, and this table has no `created_at`. Both were
                // wrong, so every press of DOWNTIME on the terminal answered 500 and no stop
                // has ever been recorded — which is also why nothing downstream had any
                // downtime to report on.
                'reported_by' => $session->employeeId,
                'remarks' => $data['remarks'] ?? null,
            ]);

            return ['downtime_log_id' => $id];
        });
    }

    /**
     * Idempotency: the same key replays the stored response rather than repeating the write.
     *
     * @param  callable(): array<string, mixed>  $work
     */

    /**
     * A terminal may only touch work on its own floor.
     *
     * The queue this terminal reads is already scoped to `$session->factoryUnitId`
     * (`FloorQueueController`), but the four write endpoints took an operation id straight off
     * the URL and never asked where that operation was. A device badged into Unit A could
     * start, log, finish or stop an operation belonging to Unit B by id alone — the business
     * guards (J2, J3, J5, QC1) all still applied, so the numbers stayed self-consistent while
     * being booked against the wrong factory. Read scoped, write unscoped, is the shape of
     * BR-54's read-around on the other side of the request.
     *
     * The job card carries the unit; the operation belongs to the card.
     */
    private function assertWithinUnit(Request $request, JobCardOperation $operation): void
    {
        $session = $request->attributes->get('device_session');

        if ($session === null) {
            abort(401, 'Device session missing.');
        }

        $unitId = DB::table('job_cards')->where('id', $operation->job_card_id)->value('factory_unit_id');

        if ($unitId === null || (int) $unitId !== (int) $session->factoryUnitId) {
            abort(403, 'This operation belongs to another factory unit. Scan in on a terminal for that unit.');
        }
    }

    /**
     * Idempotency: the same key replays the stored response rather than repeating the write.
     *
     * The key is scoped to the device session, the endpoint and the operation, not taken on
     * its own. A bare key is global: one value reused across `start` and `log` — a client bug,
     * a device whose `crypto.randomUUID` fell back to something weaker, a captured request
     * replayed — returned the *first* call's stored response and skipped the second write
     * entirely. The terminal reads that as a 200 and clears the form, so a shift's output
     * disappears with no error anywhere. Scoping makes a collision across two different writes
     * impossible rather than unlikely.
     */
    private function idempotent(Request $request, JobCardOperation $operation, string $action, callable $work): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return response()->json([
                'message' => 'Idempotency-Key header is required on shop-floor writes.',
            ], 428);
        }

        // Every caller runs `assertWithinUnit()` first, which aborts 401 when the session is
        // missing, so by here there is one.
        $session = $request->attributes->get('device_session');

        $cacheKey = 'idempotency:'.hash('sha256', implode('|', [
            $session->token,
            $action,
            (string) $operation->getKey(),
            $key,
        ]));

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

        if (! is_string($occurredAt) || $occurredAt === '') {
            return CarbonImmutable::now();
        }

        // `occurred_at` is in no endpoint's validation rules — it is a queue stamp the client
        // adds, not a field an operator fills — so it arrives unchecked. `parse()` throws on
        // anything it cannot read, which surfaced as a 500; the offline queue treats a 5xx as
        // a permanent reject and never retries, so one malformed stamp silently destroyed the
        // shift booking it was attached to. A stamp that cannot be read is a broken clock,
        // and a broken clock means now.
        try {
            $parsed = CarbonImmutable::parse($occurredAt);
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }

        // A clock ahead of the server is a device with a wrong clock, not a time traveller.
        return $parsed->isFuture() ? CarbonImmutable::now() : $parsed;
    }
}
