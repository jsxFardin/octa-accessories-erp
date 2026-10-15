<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\States;

use App\Modules\Dispatch\Models\ExportShipment;
use App\Modules\Dispatch\Services\ExportDocumentChecklist;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Modules\Trade\States\ExportLcStateMachine;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * Export shipment lifecycle (DF-6):
 *
 *   draft → booked → shipped → presented → delivered → closed
 *                           ↘ delivered (no credit: nothing to present)
 *   cancelled while nothing has shipped.
 *
 * `shipped` stamps the date and is what moves the credit the shipment is under to `shipped`;
 * `presented` is the document set handed to the bank and moves the credit with it; `closed`
 * needs the document checklist complete (AC3).
 *
 * @extends StateMachine<ExportShipment>
 */
class ExportShipmentStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly ExportDocumentChecklist $checklist,
        private readonly ExportLcStateMachine $credits,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            ExportShipment::DRAFT => [ExportShipment::BOOKED, ExportShipment::CANCELLED],
            ExportShipment::BOOKED => [ExportShipment::SHIPPED, ExportShipment::CANCELLED],
            ExportShipment::SHIPPED => [ExportShipment::PRESENTED, ExportShipment::DELIVERED],
            ExportShipment::PRESENTED => [ExportShipment::DELIVERED],
            ExportShipment::DELIVERED => [ExportShipment::CLOSED],
            ExportShipment::CLOSED => [],
            ExportShipment::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            ExportShipment::BOOKED => 'export_shipment.update',
            ExportShipment::SHIPPED => 'export_shipment.ship',
            ExportShipment::PRESENTED => 'export_shipment.update',
            ExportShipment::DELIVERED => 'export_shipment.update',
            ExportShipment::CLOSED => 'export_shipment.close',
            ExportShipment::CANCELLED => 'export_shipment.update',
        ];
    }

    /**
     * @param  ExportShipment  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === ExportShipment::BOOKED && $document->challans()->count() === 0) {
            throw TransitionDenied::guard('DF-6', 'A shipment is booked with the delivery notes it carries; attach at least one first.');
        }

        if ($to === ExportShipment::SHIPPED) {
            if ($document->challans()->count() === 0) {
                throw TransitionDenied::guard('DF-6', 'Nothing is on this shipment yet; attach the delivery notes before marking it shipped.');
            }

            $letter = $document->letterOfCredit;

            if ($letter !== null && $letter->status === LetterOfCredit::ADVISED) {
                throw TransitionDenied::guard('TR-E2', "Export LC {$letter->number} has not been accepted; accept its terms before shipping under it.");
            }

            if ($letter !== null && $letter->last_shipment_date !== null) {
                $on = isset($context['shipped_on']) ? \Illuminate\Support\Carbon::parse((string) $context['shipped_on']) : now();

                if ($on->startOfDay()->gt($letter->last_shipment_date)) {
                    throw TransitionDenied::guard('TR-E2', "Export LC {$letter->number} allows shipment up to {$letter->last_shipment_date->toDateString()}; this one would ship after it. Amend the credit first.");
                }
            }
        }

        if ($to === ExportShipment::PRESENTED && $document->lc_id === null) {
            throw TransitionDenied::guard('DF-6', 'This shipment is not under a letter of credit, so there are no documents to present to a bank; mark it delivered when it arrives.');
        }

        if ($to === ExportShipment::CLOSED) {
            $missing = $this->checklist->missing($document);

            if ($missing !== []) {
                $names = array_map(fn (string $type): string => ExportDocumentChecklist::LABELS[$type] ?? $type, $missing);

                throw TransitionDenied::guard('DF-6', 'The shipment cannot be treated as complete: still missing '.implode(', ', $names).'.');
            }
        }

        if ($to === ExportShipment::CANCELLED && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('DF-6', 'Say why the shipment is cancelled.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function auditContext(array $context): array
    {
        return [...parent::auditContext($context), ...array_intersect_key($context, array_flip(['shipped_on', 'presented_on', 'delivered_on', 'because']))];
    }

    /**
     * @param  ExportShipment  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            ExportShipment::BOOKED => $document->number === null
                ? $document->forceFill(['number' => $this->numbers->next('export_shipment')])->save()
                : null,
            ExportShipment::SHIPPED => $this->onShipped($document, $context),
            ExportShipment::PRESENTED => $this->onPresented($document, $context),
            ExportShipment::DELIVERED => $document->forceFill(['delivered_on' => $context['delivered_on'] ?? now()->toDateString()])->save(),
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    private function onShipped(ExportShipment $shipment, array $context): void
    {
        $shipment->forceFill(['shipped_on' => $context['shipped_on'] ?? now()->toDateString()])->save();

        $letter = $shipment->letterOfCredit;

        if ($letter !== null && $letter->status === LetterOfCredit::ACCEPTED) {
            StateMachine::asSystem(fn () => $this->credits->transition($letter, LetterOfCredit::SHIPPED, [
                'shipment' => $shipment->number ?? "#{$shipment->id}",
                'because' => 'export shipment left',
            ]));
        }
    }

    /** @param array<string, mixed> $context */
    private function onPresented(ExportShipment $shipment, array $context): void
    {
        $on = $context['presented_on'] ?? now()->toDateString();
        $shipment->forceFill(['presented_on' => $on])->save();

        $letter = $shipment->letterOfCredit;

        if ($letter !== null && $letter->status === LetterOfCredit::SHIPPED) {
            StateMachine::asSystem(fn () => $this->credits->transition($letter, LetterOfCredit::PRESENTED, [
                'presented_on' => $on,
                'shipment' => $shipment->number ?? "#{$shipment->id}",
                'because' => 'documents presented for the shipment',
            ]));
        }
    }
}
