<?php

declare(strict_types=1);

namespace App\Modules\Sampling\States;

use App\Modules\Sampling\Models\SampleRequest;
use App\Modules\Sampling\Models\SampleRequestLine;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * Sample request lifecycle (AS-5 … AS-7).
 *
 * Requested is the start, not a draft: the request is numbered when it is raised. Development
 * and production are where the sample room says what it is doing; ready is when the pieces
 * exist; dispatched is written by the dispatch itself, never by hand; approved and rejected are
 * reached when the customer's decision on the last line comes in.
 *
 * @extends StateMachine<SampleRequest>
 */
class SampleRequestStateMachine extends StateMachine
{
    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            // Straight to ready: a counter sample picked from stock needs no development run.
            SampleRequest::REQUESTED => [SampleRequest::IN_DEVELOPMENT, SampleRequest::IN_PRODUCTION, SampleRequest::READY, SampleRequest::CANCELLED],
            SampleRequest::IN_DEVELOPMENT => [SampleRequest::IN_PRODUCTION, SampleRequest::READY, SampleRequest::CANCELLED],
            SampleRequest::IN_PRODUCTION => [SampleRequest::READY, SampleRequest::CANCELLED],
            SampleRequest::READY => [SampleRequest::DISPATCHED, SampleRequest::CANCELLED],
            SampleRequest::DISPATCHED => [SampleRequest::APPROVED, SampleRequest::REJECTED],
            SampleRequest::APPROVED => [],
            SampleRequest::REJECTED => [],
            SampleRequest::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            SampleRequest::IN_DEVELOPMENT => 'sample_request.update',
            SampleRequest::IN_PRODUCTION => 'sample_request.update',
            SampleRequest::READY => 'sample_request.update',
            SampleRequest::CANCELLED => 'sample_request.update',
            // AS-6 — the sample room's own verb; reached through the dispatch action.
            SampleRequest::DISPATCHED => 'sample_request.dispatch',
            // AS-7 — reached when the last line is decided; the decision is the permitted act.
            SampleRequest::APPROVED => 'sample_request.update',
            SampleRequest::REJECTED => 'sample_request.update',
        ];
    }

    /**
     * @param  SampleRequest  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === SampleRequest::READY && $document->lines()->count() === 0) {
            throw TransitionDenied::guard('AS-5', 'A request with no lines has nothing to make ready. Add what the customer asked for first.');
        }

        if ($to === SampleRequest::CANCELLED && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('AS-5', 'Say why the request is cancelled; the merchandiser who raised it will read it.');
        }

        if ($to === SampleRequest::DISPATCHED && ! $document->dispatches()->exists()) {
            throw TransitionDenied::guard('AS-6', 'A request is dispatched by recording the dispatch — courier, tracking number and recipient — not by changing its status.');
        }

        if (in_array($to, [SampleRequest::APPROVED, SampleRequest::REJECTED], true)) {
            $this->guardDecided($document, $to);
        }
    }

    /** AS-7 — the request's verdict is the sum of its lines, and every line must have one. */
    private function guardDecided(SampleRequest $request, string $to): void
    {
        $statuses = $request->lines()->pluck('status');

        $undecided = $statuses->reject(fn (string $status): bool => in_array($status, [SampleRequestLine::APPROVED, SampleRequestLine::REJECTED], true))->count();

        if ($undecided > 0) {
            throw TransitionDenied::guard('AS-7', "{$undecided} line(s) still wait on the customer's decision. The request is approved or rejected once every line is.");
        }

        $anyRejected = $statuses->contains(SampleRequestLine::REJECTED);

        if ($to === SampleRequest::APPROVED && $anyRejected) {
            throw TransitionDenied::guard('AS-7', 'A line was rejected, so the request is rejected: the customer did not approve the sample as a whole.');
        }

        if ($to === SampleRequest::REJECTED && ! $anyRejected) {
            throw TransitionDenied::guard('AS-7', 'Every line was approved; the request is approved, not rejected.');
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
            ...array_intersect_key($context, array_flip(['because', 'courier_name', 'tracking_no', 'recipient', 'job_card'])),
        ];
    }
}
