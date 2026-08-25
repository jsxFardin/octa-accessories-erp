<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\Audit\AuditLogger;
use App\Support\States\StateMachine;
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

                // J7 / J2 — an operation's quantities may not exist independently of the
                // step before it. Only `start` used to ask this, so posting straight to
                // `log` recorded 5,000 good against three `pending` steps whose predecessor
                // had produced nothing, and handed 21,500 to a packing step fed by a folding
                // step that reported zero.
                $this->guardChain($locked, $addedInput);

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

                $card = $locked->jobCard;

                // The input a step receives is bounded too. A mis-keyed 5000 against a plan
                // of 121 was accepted in silence, and every later booking on that step then
                // measured itself against a false ceiling.
                if ($addedInput > 0 && $card !== null) {
                    $inputCeiling = (float) $locked->planned_qty * (1 + (float) $card->overrun_tolerance_pct / 100);

                    if ($locked->planned_qty > 0
                        && $newInput > $inputCeiling + 0.000001
                        && blank($data['input_override_reason'] ?? null)) {
                        abort(422, sprintf(
                            'J3: %.3f handed to this operation exceeds its %.3f plan. Re-check the figure, or record why more was fed in.',
                            $newInput,
                            $inputCeiling,
                        ));
                    }
                }

                // J5 at the moment of booking, not only when the card closes. The terminal
                // shows the ceiling on every screen, so a refusal that arrives days later at
                // `closed` — with the goods already made — reads as the rule not existing.
                // Only the last operation makes pieces; the ones before it make metres, and a
                // ceiling in pieces has nothing to say about them (P0-2).
                $isFinal = $locked->sequence_no === (int) JobCardOperation::query()
                    ->where('job_card_id', $locked->job_card_id)
                    ->max('sequence_no');

                if ($card !== null && $isFinal) {
                    $ceiling = $card->overrunCeiling();
                    $produced = (float) $locked->good_qty + (float) $locked->waste_qty + $good + $waste;

                    if ($produced > $ceiling + 0.000001) {
                        abort(422, sprintf(
                            'J5: %.3f would take this job past its %.3f ceiling (planned plus %s%% overrun).',
                            $produced,
                            $ceiling,
                            rtrim(rtrim((string) $card->overrun_tolerance_pct, '0'), '.'),
                        ));
                    }
                }

                $logId = DB::table('operation_logs')->insertGetId([
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

                // Production is a business event, and it is the one the trail was missing:
                // `operation_logs` has no model of its own, so it goes through the same
                // writer the reference tables use rather than a second mechanism.
                app(AuditLogger::class)->recordTable('operation_logs', (int) $logId, 'created', null, [
                    'job_card_id' => $locked->job_card_id,
                    'job_card_operation_id' => $locked->id,
                    'operation' => $locked->name,
                    'good_qty' => $good,
                    'waste_qty' => $waste,
                    'input_qty' => $addedInput,
                    'unit' => $locked->unit(),
                    'operator_id' => $session->employeeId,
                ]);

                $locked->forceFill([
                    'input_qty' => $newInput,
                    'good_qty' => $newGood,
                    'waste_qty' => $newWaste,
                    // Booking output on a step that is queued is how the terminal is used —
                    // the operator presses `+ OUTPUT`, not `START` then `+ OUTPUT`. The step
                    // has passed the same J2/QC1 gates `start` applies, so it opens here
                    // rather than staying `pending` with production against it.
                    'status' => $locked->status === JobCardOperation::PENDING
                        || $locked->status === JobCardOperation::READY
                            ? JobCardOperation::IN_PROGRESS
                            : $locked->status,
                    'started_at' => $locked->started_at ?? $occurredAt,
                ])->save();

                $jobCard = $card;

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
     * J7 — the operation chain, enforced where production is recorded rather than only
     * where it is started.
     *
     * Three questions, in the order a supervisor would ask them: is this step still open, is
     * the step before it done, and is there enough of what that step made to hand over? The
     * third is asked only when the two steps count in the same unit — weaving makes metres and
     * cutting makes pieces out of them, and a metre figure has nothing to say about a piece
     * figure (see JobCardOperation::inputAvailableFromPredecessor()).
     */
    private function guardChain(JobCardOperation $operation, float $addedInput): void
    {
        if (! $operation->acceptsProduction()) {
            abort(422, sprintf(
                'Production cannot be recorded for %s because the step is %s. Reopen it, or record against the step that is running.',
                $operation->name,
                str_replace('_', ' ', $operation->status),
            ));
        }

        $blocker = $operation->blockingPredecessor();

        if ($blocker !== null) {
            $made = (float) $blocker->good_qty;

            abort(422, sprintf(
                'Production cannot be recorded for %s because %s (step %d) is still %s%s. Finish %s first, or ask a planner to skip it.',
                $operation->name,
                $blocker->name,
                $blocker->sequence_no,
                str_replace('_', ' ', $blocker->status),
                $made > 0
                    ? sprintf(' with %s %s booked', $this->trim($made), $blocker->unit())
                    : ' and has produced nothing',
                $blocker->name,
            ));
        }

        // QC1 — the same gate `start` applies. Skipping `start` used to skip it too.
        if (! $operation->qcClearedUpstream()) {
            abort(422, sprintf(
                'Production cannot be recorded for %s because an earlier operation needs an accepted inspection first (QC1). Ask QC to pass it.',
                $operation->name,
            ));
        }

        if ($addedInput <= 0) {
            return;
        }

        $available = $operation->inputAvailableFromPredecessor();

        if ($available === null) {
            return;
        }

        $predecessor = $operation->feedingPredecessor();
        $wouldHold = (float) $operation->input_qty + $addedInput;

        if ($wouldHold > $available + 0.000001) {
            abort(422, sprintf(
                'Production cannot be recorded for %s because %s has produced %s %s, and %s %s would already have been handed to %s. Record %s\'s output first, or reduce the input.',
                $operation->name,
                $predecessor->name,
                $this->trim($available),
                $predecessor->unit(),
                $this->trim($wouldHold),
                $operation->unit(),
                $operation->name,
                $predecessor->name,
            ));
        }
    }

    /** A DECIMAL(18,6) figure as a person would write it. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ','), '0'), '.');
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
