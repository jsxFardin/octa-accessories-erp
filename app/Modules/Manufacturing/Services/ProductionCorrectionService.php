<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoing a shift booking — the correction path production never had.
 *
 * Inventory settled this argument long ago (I1): a correction is a reversing entry, never an
 * edit. Production had neither. `operation_logs` rows were written once by the terminal and
 * never listed, edited or undone; `job_card_operations.good_qty` only ever accumulated; and
 * `sales_order_lines.produced_qty` was `increment()`-ed by the final operation and decremented
 * by nothing at all. An operator who typed 5,000 where they meant 500 left a figure no screen
 * in the application could correct, which the order line's fulfilment position then carried
 * for the life of the order — and which the S2 cancellation guard and the "remaining to cover"
 * filter both read as fact.
 *
 * The original row is never touched. It is what the operator recorded, and rewriting it would
 * destroy the evidence that the mistake happened; the reversal is a second row with negated
 * quantities pointing back at it.
 */
class ProductionCorrectionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Reverse one shift booking.
     *
     * @param  object  $log  a row from `operation_logs`
     *
     * @throws ValidationException when the booking cannot be reversed
     */
    public function reverse(JobCardOperation $operation, object $log, string $reason, ?int $userId): int
    {
        return DB::transaction(function () use ($operation, $log, $reason, $userId): int {
            /** @var JobCardOperation $locked */
            $locked = JobCardOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

            $this->assertReversible($locked, $log);

            $good = (float) $log->good_qty;
            $waste = (float) $log->waste_qty;
            // Rows booked before `operation_logs.input_qty` existed carry 0, because for them
            // the figure genuinely was never captured.
            $input = (float) ($log->input_qty ?? 0);

            // Reversing more than the operation currently holds would drive it negative, which
            // is not a correction but a second error. It happens when two bookings are
            // reversed out of order after one of them has already been partly unwound.
            if ($good > (float) $locked->good_qty + 0.000001
                || $waste > (float) $locked->waste_qty + 0.000001
                || $input > (float) $locked->input_qty + 0.000001) {
                throw ValidationException::withMessages([
                    'operation_log_id' => sprintf(
                        'That booking is larger than what %s currently holds (%s input, %s good, %s waste). Reverse the later bookings first.',
                        $locked->name,
                        $this->trim((float) $locked->input_qty),
                        $this->trim((float) $locked->good_qty),
                        $this->trim((float) $locked->waste_qty),
                    ),
                ]);
            }

            $reversalId = (int) DB::table('operation_logs')->insertGetId([
                'job_card_operation_id' => $locked->id,
                'reverses_log_id' => $log->id,
                'input_qty' => -$input,
                'machine_id' => $log->machine_id,
                'operator_id' => $log->operator_id,
                'shift_id' => $log->shift_id,
                // The booking being cancelled is the event this row is about, so it carries
                // that booking's own window rather than the moment someone noticed.
                'started_at' => $log->started_at,
                'ended_at' => $log->ended_at,
                'good_qty' => -$good,
                'waste_qty' => -$waste,
                'input_lot_id' => $log->input_lot_id,
                'output_lot_id' => $log->output_lot_id,
                'remarks' => 'Reversal of log #'.$log->id,
                'reversal_reason' => $reason,
                'created_at' => now(),
                'created_by' => $userId,
            ]);

            $locked->forceFill([
                'good_qty' => (float) $locked->good_qty - $good,
                'waste_qty' => (float) $locked->waste_qty - $waste,
                // The input goes back with the output it was handed over for. Leaving it left
                // an operation holding material it had produced nothing from, and refused the
                // next legitimate booking of the same quantity as an overrun.
                'input_qty' => (float) $locked->input_qty - $input,
            ])->save();

            /*
             * The waste this booking recorded goes back out with it.
             *
             * `waste_logs` rows written by the terminal are derived from the booking, not an
             * independent observation, so a reversed booking must not leave its waste standing
             * in the waste report. They are removed rather than negated because
             * `waste_logs_qty_chk` requires `qty > 0` — there is no reversing entry to write on
             * that table. The reversal itself stays permanently on `operation_logs` and on the
             * audit trail, which is where the evidence belongs.
             *
             * A waste record raised by hand against no booking has a null `operation_log_id`
             * and is nobody's to remove.
             */
            $wasteRemoved = DB::table('waste_logs')->where('operation_log_id', $log->id)->delete();

            $this->rollBackCard($locked, $good, $waste);

            $this->audit->recordTable('operation_logs', $reversalId, 'created', null, [
                'reverses_log_id' => $log->id,
                'job_card_id' => $locked->job_card_id,
                'job_card_operation_id' => $locked->id,
                'operation' => $locked->name,
                'good_qty' => -$good,
                'waste_qty' => -$waste,
                'input_qty' => -$input,
                'unit' => $locked->unit(),
                'waste_logs_removed' => $wasteRemoved,
                'reversal_reason' => $reason,
            ]);

            return $reversalId;
        });
    }

    /** @throws ValidationException */
    private function assertReversible(JobCardOperation $operation, object $log): void
    {
        if ((int) $log->job_card_operation_id !== (int) $operation->getKey()) {
            throw ValidationException::withMessages([
                'operation_log_id' => 'That booking belongs to a different operation.',
            ]);
        }

        // A reversal is not itself reversible: undoing one would be a re-booking, and the way
        // to re-book output is to book it.
        if ($log->reverses_log_id !== null) {
            throw ValidationException::withMessages([
                'operation_log_id' => 'That row is already a reversal. Book the output again if it was reversed in error.',
            ]);
        }

        // The unique key on `reverses_log_id` is the real guard; this is the readable one.
        $existing = DB::table('operation_logs')->where('reverses_log_id', $log->id)->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'operation_log_id' => 'That booking has already been reversed.',
            ]);
        }

        $status = $operation->jobCard?->status;

        if (in_array($status, [JobCard::CLOSED, JobCard::CANCELLED], true)) {
            throw ValidationException::withMessages([
                'operation_log_id' => "This job card is {$status}. Reopen it before correcting its production.",
            ]);
        }
    }

    /**
     * The same totals the booking moved, moved back.
     *
     * P0-2 — only the final operation's good output reaches the order line, so only a reversal
     * on the final operation takes it off again. 50,000 labels woven, cut and folded is 50,000
     * produced, and un-booking the fold is what un-produces them.
     */
    private function rollBackCard(JobCardOperation $operation, float $good, float $waste): void
    {
        $card = $operation->jobCard;

        if ($card === null) {
            return;
        }

        $card->forceFill([
            'good_qty_running' => (float) $card->good_qty_running - $good,
            'waste_qty_running' => (float) $card->waste_qty_running - $waste,
            'produced_qty_running' => (float) $card->produced_qty_running - $good - $waste,
        ])->save();

        $finalSequence = (int) JobCardOperation::query()
            ->where('job_card_id', $operation->job_card_id)
            ->max('sequence_no');

        if ($good > 0
            && $operation->sequence_no === $finalSequence
            && $card->sales_order_line_id !== null) {
            // Atomic, and floored at zero: the column is the order line's fulfilment position
            // and a negative one would read as a debt the factory owes itself.
            DB::table('sales_order_lines')
                ->where('id', $card->sales_order_line_id)
                ->update([
                    'produced_qty' => DB::raw('GREATEST(0, produced_qty - '.(float) $good.')'),
                ]);
        }
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ','), '0'), '.');
    }
}
