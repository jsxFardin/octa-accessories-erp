<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Services;

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\JobCardFactory;
use App\Modules\Sampling\Models\SampleRequest;
use App\Modules\Sampling\Models\SampleRequestLine;
use App\Modules\Sampling\States\SampleRequestStateMachine;
use App\Support\States\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * AS-5 AC4 — a sample line produces a sample job card, so sample production consumes real
 * material and is costed like any other run; and the run's completion is what marks the line
 * produced and the request ready.
 */
class SampleProduction
{
    public function __construct(
        private readonly JobCardFactory $jobCards,
        private readonly SampleRequestStateMachine $states,
    ) {}

    /**
     * @param  array{factory_unit_id: int|string, planned_qty: float|int|string, due_date?: string|null, priority?: int|string|null}  $data
     */
    public function raiseJobCard(SampleRequestLine $line, array $data, User $actor): JobCard
    {
        return DB::transaction(function () use ($line, $data, $actor): JobCard {
            $jobCard = $this->jobCards->create($data, $actor, sampleLine: $line, field: 'planned_qty');

            $request = $line->request;

            // Raising the run is what moves the request into production; a request still in
            // development or just requested follows the card, so nobody edits the status by hand.
            if (in_array($request->status, [SampleRequest::REQUESTED, SampleRequest::IN_DEVELOPMENT], true)) {
                StateMachine::asSystem(fn () => $this->states->transition($request, SampleRequest::IN_PRODUCTION, [
                    'because' => 'sample job card raised',
                    'job_card' => $jobCard->number ?? "#{$jobCard->id}",
                ]));
            }

            return $jobCard;
        });
    }

    /**
     * Called when a job card completes: a sample card marks its line produced, and once every
     * line on the request has pieces the request is ready to dispatch.
     */
    public function onJobCardCompleted(JobCard $jobCard): void
    {
        if ($jobCard->sample_request_line_id === null) {
            return;
        }

        $line = SampleRequestLine::query()->find($jobCard->sample_request_line_id);

        if ($line === null) {
            return;
        }

        if ($line->status === SampleRequestLine::PENDING) {
            $line->forceFill(['status' => SampleRequestLine::PRODUCED])->save();
        }

        $request = $line->request;
        $allProduced = ! $request->lines()->where('status', SampleRequestLine::PENDING)->exists();

        if ($allProduced && in_array($request->status, [SampleRequest::REQUESTED, SampleRequest::IN_DEVELOPMENT, SampleRequest::IN_PRODUCTION], true)) {
            StateMachine::asSystem(fn () => $this->states->transition($request, SampleRequest::READY, [
                'because' => 'every line produced',
                'job_card' => $jobCard->number ?? "#{$jobCard->id}",
            ]));
        }
    }
}
