<?php

declare(strict_types=1);

namespace App\Modules\Product\States;

use App\Modules\Product\Models\ArtworkVersion;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * 05-workflows §4 — the artwork version lifecycle.
 *
 * Approval supersedes the previous approved version **in the same transaction** (A2). The
 * database would reject the second approved row anyway; doing it in order inside one
 * transaction is what turns that from an error into a workflow.
 *
 * @extends StateMachine<ArtworkVersion>
 */
class ArtworkVersionStateMachine extends StateMachine
{
    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            ArtworkVersion::DRAFT => [ArtworkVersion::SUBMITTED],
            ArtworkVersion::SUBMITTED => [ArtworkVersion::APPROVED, ArtworkVersion::REJECTED, ArtworkVersion::DRAFT],
            ArtworkVersion::APPROVED => [ArtworkVersion::SUPERSEDED],
            ArtworkVersion::REJECTED => [],
            ArtworkVersion::SUPERSEDED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            ArtworkVersion::SUBMITTED => 'artwork.submit',
            ArtworkVersion::APPROVED => 'artwork.approve',
            ArtworkVersion::REJECTED => 'artwork.approve',
            ArtworkVersion::SUPERSEDED => 'artwork.approve',
        ];
    }

    /**
     * @param  ArtworkVersion  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === ArtworkVersion::SUBMITTED && $document->checksum_sha256 === null) {
            throw TransitionDenied::guard(
                'A3',
                'The artwork file must be uploaded and checksummed before it is submitted to the customer.',
            );
        }

        if ($to === ArtworkVersion::APPROVED && blank($context['customer_ref'] ?? $document->customer_ref)) {
            // Evidence is required: an approval with no customer reference is a claim, not a
            // record, and it is the first thing a brand disputes after a bad delivery.
            throw TransitionDenied::guard(
                'A2',
                'Approving a version requires the customer reference that evidences the sign-off.',
            );
        }

        if ($to === ArtworkVersion::REJECTED && blank($context['rejection_reason'] ?? null)) {
            throw TransitionDenied::guard('A2', 'A rejection needs a reason; the studio has to know what to change.');
        }
    }

    /**
     * A2 — supersede the outgoing version *before* this one becomes approved.
     *
     * The unique index over `approved_key` admits exactly one approved row per artwork, so
     * the order is not stylistic: writing `approved` first would collide with the row it is
     * about to replace, and the transaction would fail on the very constraint that makes the
     * gate trustworthy.
     *
     * @param  ArtworkVersion  $document
     * @param  array<string, mixed>  $context
     */
    protected function prepare(Model $document, string $from, string $to, array $context): void
    {
        if ($to !== ArtworkVersion::APPROVED) {
            return;
        }

        ArtworkVersion::query()
            ->where('artwork_id', $document->artwork_id)
            ->where('id', '!=', $document->getKey())
            ->where('status', ArtworkVersion::APPROVED)
            ->update(['status' => ArtworkVersion::SUPERSEDED]);
    }

    /**
     * @param  ArtworkVersion  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            ArtworkVersion::SUBMITTED => $this->onSubmitted($document),
            ArtworkVersion::APPROVED => $this->onApproved($document, $context),
            ArtworkVersion::REJECTED => $this->onRejected($document, $context),
            default => null,
        };
    }

    private function onSubmitted(ArtworkVersion $version): void
    {
        $version->forceFill(['submitted_at' => now()])->save();
    }

    /** @param array<string, mixed> $context */
    private function onApproved(ArtworkVersion $version, array $context): void
    {
        $version->forceFill([
            'approved_at' => now(),
            'approved_by' => auth()->id(),
            'customer_ref' => $context['customer_ref'] ?? $version->customer_ref,
        ])->save();
    }

    /** @param array<string, mixed> $context */
    private function onRejected(ArtworkVersion $version, array $context): void
    {
        $version->forceFill(['rejection_reason' => $context['rejection_reason']])->save();
    }
}
