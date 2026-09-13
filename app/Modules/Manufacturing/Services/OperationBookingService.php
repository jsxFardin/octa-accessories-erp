<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\Audit\AuditLogger;
use App\Support\States\StateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking a shift's output against an operation — the one place it happens, whichever door it
 * came through.
 *
 * This lived inside `OperationEventController::log()`, reachable only with a device session.
 * That is the right default: output is the shop-floor truth, and a desk form invites
 * yesterday's numbers being typed off paper, which is the habit G1 exists to end. It is not a
 * workable *only* option — a kiosk that dies mid-shift stranded that shift's output with no
 * route in at all.
 *
 * So there are two doors now, and exactly one set of rules behind them. J7 (the chain), J3
 * (output within input, input within plan) and J5 (the job's ceiling) are asked here rather
 * than at either caller, because a guard that exists on one path and not the other is worse
 * than no guard: it makes the weaker door the one people learn to use.
 */
class OperationBookingService
{
    /**
     * The vocabulary `waste_logs_type_chk` allows. Here rather than on either controller,
     * because both doors validate against it and a list that drifts between them would let one
     * accept a value the database then refuses.
     */
    public const WASTE_TYPES = [
        'setup', 'shade', 'print_defect', 'weave_defect',
        'cutting', 'edge_trim', 'damaged', 'expired', 'other',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly JobCardStateMachine $jobCards,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the validated booking
     * @param  int|null  $operatorId  the employee the work belongs to
     * @param  int|null  $enteredBy  the user keying it, when that is not the operator
     * @param  string|null  $manualReason  set only on a desk booking; null means the terminal
     * @return int the `operation_logs` id
     */
    public function book(
        JobCardOperation $operation,
        array $data,
        CarbonImmutable $occurredAt,
        ?int $operatorId,
        ?int $enteredBy = null,
        ?string $manualReason = null,
    ): int {
        $good = (float) $data['good_qty'];
        $waste = (float) ($data['waste_qty'] ?? 0);
        $addedInput = (float) ($data['input_qty'] ?? 0);

        return DB::transaction(function () use (
            $operation, $data, $good, $waste, $addedInput, $occurredAt, $operatorId, $enteredBy, $manualReason
        ): int {
            /** @var JobCardOperation $locked */
            $locked = JobCardOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

            // J1 — production may not be recorded against a card that was never released.
            //
            // On the terminal this was never a write-side check: `FloorQueueController` only
            // ever offers operations whose card is released, in production or on hold, so an
            // unreleased card could not be reached to book against. The desk door has no queue
            // in front of it, and without this it booked output against a `planned` card and
            // opened its first step — production running on a card the release gate had not
            // passed, which is the whole thing J1 exists to stop.
            $this->assertCardIsRunnable($locked);

            // J7 / J2 — an operation's quantities may not exist independently of the step
            // before it. Only `start` used to ask this, so posting straight to `log` recorded
            // 5,000 good against three `pending` steps whose predecessor had produced nothing.
            $this->guardChain($locked, $addedInput);

            $newInput = (float) $locked->input_qty + $addedInput;
            $newGood = (float) $locked->good_qty + $good;
            $newWaste = (float) $locked->waste_qty + $waste;

            if ($newGood + $newWaste > $newInput + 0.000001) {
                $this->refuse('good_qty', sprintf(
                    'J3: output %.3f exceeds the %.3f handed to this operation. Record the input first.',
                    $newGood + $newWaste,
                    $newInput,
                ));
            }

            $card = $locked->jobCard;

            // The input a step receives is bounded too. A mis-keyed 5000 against a plan of 121
            // was accepted in silence, and every later booking on that step then measured
            // itself against a false ceiling.
            if ($addedInput > 0 && $card !== null) {
                $inputCeiling = (float) $locked->planned_qty * (1 + (float) $card->overrun_tolerance_pct / 100);

                if ($locked->planned_qty > 0
                    && $newInput > $inputCeiling + 0.000001
                    && blank($data['input_override_reason'] ?? null)) {
                    $this->refuse('input_override_reason', sprintf(
                        'J3: %.3f handed to this operation exceeds its %.3f plan. Re-check the figure, or record why more was fed in.',
                        $newInput,
                        $inputCeiling,
                    ));
                }
            }

            // J5 at the moment of booking, not only when the card closes. Only the last
            // operation makes pieces; the ones before it make metres, and a ceiling in pieces
            // has nothing to say about them (P0-2).
            $isFinal = $locked->sequence_no === (int) JobCardOperation::query()
                ->where('job_card_id', $locked->job_card_id)
                ->max('sequence_no');

            if ($card !== null && $isFinal) {
                $ceiling = $card->overrunCeiling();
                $produced = (float) $locked->good_qty + (float) $locked->waste_qty + $good + $waste;

                if ($produced > $ceiling + 0.000001) {
                    $this->refuse('good_qty', sprintf(
                        'J5: %.3f would take this job past its %.3f ceiling (planned plus %s%% overrun).',
                        $produced,
                        $ceiling,
                        rtrim(rtrim((string) $card->overrun_tolerance_pct, '0'), '.'),
                    ));
                }
            }

            $logId = (int) DB::table('operation_logs')->insertGetId([
                'job_card_operation_id' => $locked->id,
                'machine_id' => $data['machine_id'] ?? $locked->machine_id,
                'operator_id' => $operatorId,
                'shift_id' => $data['shift_id'] ?? null,
                'started_at' => $locked->started_at ?? $occurredAt,
                'ended_at' => $occurredAt,
                // What this booking handed over. The running total still lives on the
                // operation row and J3 is still checked against it — this is here so a
                // reversal can take the same figure back, which it could not do while input
                // was recorded nowhere but in the sum.
                'input_qty' => $addedInput,
                'good_qty' => $good,
                'waste_qty' => $waste,
                'input_lot_id' => $data['input_lot_id'] ?? null,
                'output_lot_id' => $data['output_lot_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                // Null means the terminal recorded it. Set means someone keyed it at a desk
                // and said why — an exception that cannot be told apart from the norm stops
                // being one.
                'manual_reason' => $manualReason,
                'created_at' => now(),
                'created_by' => $enteredBy,
            ]);

            $this->recordWaste($locked, $data, $waste, $occurredAt, $operatorId, $logId);

            // Production is a business event, and it is the one the trail was missing:
            // `operation_logs` has no model of its own, so it goes through the same writer the
            // reference tables use rather than a second mechanism.
            $this->audit->recordTable('operation_logs', $logId, 'created', null, [
                'job_card_id' => $locked->job_card_id,
                'job_card_operation_id' => $locked->id,
                'operation' => $locked->name,
                'good_qty' => $good,
                'waste_qty' => $waste,
                'input_qty' => $addedInput,
                'unit' => $locked->unit(),
                'operator_id' => $operatorId,
                'entered_by' => $enteredBy,
                'manual_reason' => $manualReason,
            ]);

            $locked->forceFill([
                'input_qty' => $newInput,
                'good_qty' => $newGood,
                'waste_qty' => $newWaste,
                // Booking output on a step that is queued is how the terminal is used — the
                // operator presses `+ OUTPUT`, not `START` then `+ OUTPUT`. The step has
                // passed the same J2/QC1 gates `start` applies, so it opens here rather than
                // staying `pending` with production against it.
                'status' => $locked->status === JobCardOperation::PENDING
                    || $locked->status === JobCardOperation::READY
                        ? JobCardOperation::IN_PROGRESS
                        : $locked->status,
                'started_at' => $locked->started_at ?? $occurredAt,
            ])->save();

            $this->rollForwardCard($locked, $card, $good, $waste);

            return $logId;
        });
    }

    /**
     * G4 — the waste, with its cause, in the table built to hold it. Written in the same
     * transaction as the booking that produced it, so the two can never disagree.
     */
    /** @param  array<string, mixed>  $data */
    private function recordWaste(
        JobCardOperation $operation,
        array $data,
        float $waste,
        CarbonImmutable $occurredAt,
        ?int $operatorId,
        int $logId,
    ): void {
        if ($waste <= 0) {
            return;
        }

        $wasteLotId = $data['input_lot_id'] ?? null;

        DB::table('waste_logs')->insert([
            'job_card_id' => $operation->job_card_id,
            'job_card_operation_id' => $operation->id,
            // The booking this waste came from. It is what lets a reversal take the waste back
            // out again: `waste_logs_qty_chk` requires `qty > 0`, so a negating entry is not
            // possible on this table.
            'operation_log_id' => $logId,
            // The material wasted is whatever the step was fed, when the operator named a lot.
            'item_id' => $wasteLotId === null
                ? null
                : DB::table('stock_lots')->where('id', $wasteLotId)->value('item_id'),
            'lot_id' => $wasteLotId,
            'waste_type' => $data['waste_type'],
            'qty' => $waste,
            'uom_id' => DB::table('uoms')->where('code', $operation->unit())->value('id'),
            // Left at zero deliberately. Costing WIP waste is the consumption and cost-sheet
            // chain's answer; a wrong value here would be worse than an absent one.
            'value' => 0,
            'occurred_at' => $occurredAt,
            'reported_by' => $operatorId,
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    private function rollForwardCard(JobCardOperation $operation, ?object $card, float $good, float $waste): void
    {
        if ($card === null) {
            return;
        }

        // Running totals, maintained in the same transaction as the event that moves them so
        // they reconcile against the logs. J6 says the job's output is the final operation's,
        // which is why these columns carry the `_running` suffix.
        $card->forceFill([
            'good_qty_running' => (float) $card->good_qty_running + $good,
            'waste_qty_running' => (float) $card->waste_qty_running + $waste,
            'produced_qty_running' => (float) $card->produced_qty_running + $good + $waste,
        ])->save();

        // A released card with production against it is in production, whichever door the
        // booking came through. The terminal's `start` does this; the desk door did not, so a
        // job worked entirely from a desk stayed `released` for its whole life: the last
        // operation's `finish` only advances a card to `qc_pending` when it is already
        // `in_production`, and the sales order never learned it was being made either.
        //
        // As the system, like the QC advance: the card moving is a consequence of production
        // being recorded, not a second act by whoever keyed it — and the person keying a desk
        // booking is often a supervisor who holds no `operation.start`.
        if ($card instanceof JobCard && $card->status === JobCard::RELEASED) {
            StateMachine::asSystem(fn () => $this->jobCards->transition($card, JobCard::IN_PRODUCTION));
        }

        // P0-2 — the order line's produced total moves with the *final* operation's good
        // output. 50,000 labels woven, cut and folded is 50,000 produced, not 150,000. Atomic
        // increment, not read-modify-write: two terminals logging the same final operation
        // must not lose an update.
        $finalSequence = (int) JobCardOperation::query()
            ->where('job_card_id', $operation->job_card_id)
            ->max('sequence_no');

        if ($good > 0
            && $operation->sequence_no === $finalSequence
            && $card->sales_order_line_id !== null) {
            DB::table('sales_order_lines')
                ->where('id', $card->sales_order_line_id)
                ->increment('produced_qty', $good);
        }
    }

    /**
     * J1 — the card has to be released before anything may be booked against it.
     *
     * The set matches `FloorQueueController`'s, which is what the terminal has always been
     * limited to, so both doors accept exactly the same cards. `on_hold` is in it because a
     * held card's output still has to be recordable: the hold stops new work starting, not the
     * shift that already ran from being written down.
     */
    private function assertCardIsRunnable(JobCardOperation $operation): void
    {
        $status = $operation->jobCard?->status;

        if (in_array($status, [JobCard::RELEASED, JobCard::IN_PRODUCTION, JobCard::ON_HOLD], true)) {
            return;
        }

        $this->refuse('job_card_operation_id', sprintf(
            'J1: this job card is %s. Production cannot be booked against it until it is released.',
            str_replace('_', ' ', (string) ($status ?? 'in no state')),
        ));
    }

    /**
     * J7 — the operation chain, enforced where production is recorded rather than only where
     * it is started.
     */
    private function guardChain(JobCardOperation $operation, float $addedInput): void
    {
        if (! $operation->acceptsProduction()) {
            $this->refuse('job_card_operation_id', sprintf(
                'Production cannot be recorded for %s because the step is %s. Reopen it, or record against the step that is running.',
                $operation->name,
                str_replace('_', ' ', $operation->status),
            ));
        }

        $blocker = $operation->blockingPredecessor();

        if ($blocker !== null) {
            $made = (float) $blocker->good_qty;

            $this->refuse('job_card_operation_id', sprintf(
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

        if (! $operation->qcClearedUpstream()) {
            $this->refuse('job_card_operation_id', 'Production cannot be recorded for '.$operation->name.' because an earlier operation needs an accepted inspection first (QC1). Ask QC to pass it.');
        }

        // Nothing is being handed over, so there is nothing to compare against what the step
        // before it made. Dropping this ran the availability check on every booking, including
        // the ones that only report good and waste against input already recorded.
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
            $this->refuse('input_qty', sprintf(
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

    /**
     * A refusal both doors can render.
     *
     * These were `abort(422)`, which is the right shape for the device API and the wrong one
     * for a form: a supervisor booking from the desk got an error page instead of the message
     * beside the field they have to change. A `ValidationException` is a 422 with the same
     * `message` on the JSON side and a field error on the web side, so one throw serves both.
     */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ','), '0'), '.');
    }
}
