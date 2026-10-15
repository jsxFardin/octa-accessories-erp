<?php

declare(strict_types=1);

namespace App\Modules\Trade\States;

use App\Modules\Trade\Models\LetterOfCredit;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * An export letter of credit — the one a customer's bank opens in our favour.
 *
 * The import ladder (draft → applied → opened …) is the applicant's; here we are the
 * beneficiary, so the bank's number exists from the day the credit is advised, and the moves
 * that matter are ours to make: accept the terms, ship, present the documents, get paid.
 *
 *   advised → accepted → shipped → presented → realised → closed
 *                              ↘ discrepant ↔ presented
 *   cancelled while nothing has shipped.
 *
 * `shipped` is written by the export shipment, never by hand (TR-E2).
 *
 * @extends StateMachine<LetterOfCredit>
 */
class ExportLcStateMachine extends StateMachine
{
    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            LetterOfCredit::ADVISED => [LetterOfCredit::ACCEPTED, LetterOfCredit::CANCELLED],
            LetterOfCredit::ACCEPTED => [LetterOfCredit::SHIPPED, LetterOfCredit::CANCELLED],
            LetterOfCredit::SHIPPED => [LetterOfCredit::PRESENTED],
            LetterOfCredit::PRESENTED => [LetterOfCredit::DISCREPANT, LetterOfCredit::REALISED],
            LetterOfCredit::DISCREPANT => [LetterOfCredit::PRESENTED],
            LetterOfCredit::REALISED => [LetterOfCredit::CLOSED],
            LetterOfCredit::CLOSED => [],
            LetterOfCredit::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            LetterOfCredit::ACCEPTED => 'export_lc.accept',
            // Reached as a consequence of a shipment leaving; the system context skips this.
            LetterOfCredit::SHIPPED => 'export_lc.update',
            LetterOfCredit::PRESENTED => 'export_lc.update',
            LetterOfCredit::DISCREPANT => 'export_lc.update',
            // Money arrived: accounts' call, not merchandising's.
            LetterOfCredit::REALISED => 'export_lc.realise',
            LetterOfCredit::CLOSED => 'export_lc.update',
            LetterOfCredit::CANCELLED => 'export_lc.update',
        ];
    }

    /**
     * @param  LetterOfCredit  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === LetterOfCredit::ACCEPTED) {
            if (blank($document->lc_no)) {
                throw TransitionDenied::guard('TR-E1', "The bank's LC number is needed before the credit is accepted; it is what every shipping document will quote.");
            }

            if ($document->expiry_date === null || $document->last_shipment_date === null) {
                throw TransitionDenied::guard('TR-E1', 'Accepting the credit means accepting its dates: record the expiry and the last shipment date first.');
            }
        }

        // The shipment that leaves names itself in the context; a bare status change has no shipment.
        if ($to === LetterOfCredit::SHIPPED && blank($context['shipment'] ?? null)) {
            throw TransitionDenied::guard('TR-E2', 'A credit is marked shipped by the export shipment that leaves against it, not by hand.');
        }

        if ($to === LetterOfCredit::DISCREPANT && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('TR-E3', 'Say what the bank found wrong; it is what the corrected presentation answers.');
        }

        if ($to === LetterOfCredit::REALISED && blank($context['realisation_ref'] ?? null)) {
            throw TransitionDenied::guard('TR-E4', "Record the bank's realisation reference; proceeds without one cannot be matched to the credit.");
        }

        if ($to === LetterOfCredit::CANCELLED && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('TR-E1', 'Say why the credit is cancelled.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function auditContext(array $context): array
    {
        return [
            ...parent::auditContext($context),
            ...array_intersect_key($context, array_flip(['because', 'realisation_ref', 'shipment', 'presented_on', 'realised_on'])),
        ];
    }

    /**
     * @param  LetterOfCredit  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            LetterOfCredit::PRESENTED => $document->forceFill(['presented_on' => $context['presented_on'] ?? now()->toDateString()])->save(),
            LetterOfCredit::REALISED => $document->forceFill([
                'realised_on' => $context['realised_on'] ?? now()->toDateString(),
                'realisation_ref' => $context['realisation_ref'],
            ])->save(),
            default => null,
        };
    }
}
