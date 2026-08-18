<?php

declare(strict_types=1);

namespace App\Modules\Quality\States;

use App\Modules\Quality\Models\TestReport;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

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
