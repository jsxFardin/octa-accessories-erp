<?php

declare(strict_types=1);

namespace App\Modules\Quality\States;

use App\Modules\Quality\Models\TestReport;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * QL-6 — test report lifecycle. Once issued, the report and its lines are immutable (QC3).
 * A correction is a new report referencing the original.
 *
 * @extends StateMachine<TestReport>
 */
class TestReportStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly NumberAllocator $numbers,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['issued', 'cancelled'],
            'issued' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'issued' => 'test_report.issue',
            'cancelled' => 'test_report.update',
        ];
    }

    /**
     * @param  TestReport  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'issued') {
            if ($document->lines()->doesntExist()) {
                throw TransitionDenied::guard('QL-6', 'A report with no results cannot be issued.');
            }

            // QL-6 AC1 / QL-5 AC4 — "mandatory" is what the customer requires for this product
            // (or for everything they buy), not merely which tests exist. A report issued
            // without one of them is a certificate that answers the wrong question.
            if ($document->customer_id !== null) {
                $missing = DB::table('customer_test_requirements as r')
                    ->join('lab_tests as t', 't.id', '=', 'r.lab_test_id')
                    ->where('r.customer_id', $document->customer_id)
                    ->where('r.is_mandatory', true)
                    ->where(fn ($q) => $q->whereNull('r.product_id')->orWhere('r.product_id', $document->product_id))
                    ->whereNotIn('r.lab_test_id', $document->lines()->select('lab_test_id'))
                    ->pluck('t.name');

                if ($missing->isNotEmpty()) {
                    throw TransitionDenied::guard('QL-6', 'The customer requires these tests on this report and they have no result yet: '.$missing->join(', ').'.');
                }
            }

            $mandatoryFailed = $document->lines()
                ->whereHas('labTest', fn ($q) => $q->where('is_active', true))
                ->where('result', 'fail')
                ->exists();

            if ($mandatoryFailed && $document->overall_result !== 'fail') {
                throw TransitionDenied::guard('QL-5', 'Overall result must be fail when any mandatory test fails.');
            }
        }
    }

    /**
     * @param  TestReport  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'issued') {
            $document->forceFill([
                'number' => $this->numbers->next('test_report'),
                'issued_at' => now(),
            ])->save();
        }
    }
}
