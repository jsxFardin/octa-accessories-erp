<?php

declare(strict_types=1);

namespace App\Modules\Costing\States;

use App\Modules\Costing\Models\CostSheet;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\Settings\Settings;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * CS-3 — a cost sheet is submitted by the clerk who built it and approved by someone who may
 * sign off the margin. Below the floor, approving also needs the right to override it.
 * Un-approving is approved → draft with a reason, and the reason is on the audit log: the
 * garments trade calls that the un-approval history, and an auditor asks for it.
 *
 * @extends StateMachine<CostSheet>
 */
class CostSheetStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly Settings $settings,
    ) {
        parent::__construct($audit);
    }

    protected function transitions(): array
    {
        return [
            CostSheet::DRAFT => [CostSheet::SUBMITTED],
            CostSheet::SUBMITTED => [CostSheet::APPROVED, CostSheet::REJECTED, CostSheet::DRAFT],
            CostSheet::APPROVED => [CostSheet::DRAFT],
            CostSheet::REJECTED => [CostSheet::DRAFT],
        ];
    }

    protected function permissions(): array
    {
        return [
            CostSheet::SUBMITTED => 'cost_sheet.submit',
            CostSheet::APPROVED => 'cost_sheet.approve',
            CostSheet::REJECTED => 'cost_sheet.approve',
            // Withdrawing a submission is the submitter's; un-approving is the approver's.
            // Both land on `draft`, so the finer check sits in the guard.
            CostSheet::DRAFT => 'cost_sheet.view',
        ];
    }

    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === CostSheet::APPROVED) {
            $floor = $this->settings->decimal('margin_floor_pct', 12);
            $margin = $document->marginPct();

            if ($margin !== null && $margin < $floor && ! (auth()->user()?->hasPermission('cost_sheet.override_margin') ?? false)) {
                throw TransitionDenied::guard('CS-3', sprintf(
                    'This sheet leaves %s%% margin, below the %s%% floor. Approving it needs someone who may accept a margin under the floor.',
                    number_format($margin, 1),
                    rtrim(rtrim(number_format($floor, 2, '.', ''), '0'), '.'),
                ));
            }
        }

        if (in_array($to, [CostSheet::REJECTED, CostSheet::DRAFT], true) && $from !== CostSheet::DRAFT && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('CS-3', $to === CostSheet::REJECTED
                ? 'Rejecting a sheet needs a reason the clerk can act on.'
                : 'Reopening a sheet needs a reason; it is what the approval history shows.');
        }

        if ($to === CostSheet::DRAFT) {
            $needs = $from === CostSheet::APPROVED ? 'cost_sheet.approve' : 'cost_sheet.submit';

            if (! (auth()->user()?->hasPermission($needs) ?? false)) {
                throw TransitionDenied::notPermitted($needs);
            }
        }

        if ($to === CostSheet::DRAFT && $document->is_locked && $document->stage !== CostSheet::MARKETING) {
            throw TransitionDenied::guard('CS-3', 'A benchmark or an actuals sheet is struck by the system and is not reopened by hand.');
        }
    }

    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            CostSheet::SUBMITTED => $document->forceFill([
                'number' => $document->number ?? $this->numbers->next('cost_sheet'),
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
            ])->save(),
            CostSheet::APPROVED => $document->forceFill(['approved_by' => auth()->id(), 'approved_at' => now()])->save(),
            CostSheet::DRAFT => $document->forceFill(['approved_by' => null, 'approved_at' => null])->save(),
            default => null,
        };
    }

    protected function auditContext(array $context): array
    {
        return array_intersect_key($context, ['reason' => true]);
    }
}
