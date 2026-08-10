<?php

declare(strict_types=1);

namespace App\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\States\PurchaseOrderStateMachine;
use App\Modules\Procurement\States\PurchaseRequisitionStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Approving many documents at once.
 *
 * A purchase manager on a Sunday morning has fifteen requisitions waiting, each one two clicks
 * and a page load. This does the same thing in a loop — and specifically *not* by relaxing
 * anything: each document goes through its own state machine, with its own guards, in its own
 * transaction. An order above the approval band still fails inside a bulk run, and the run
 * names it rather than skipping quietly.
 *
 * Partial success is the normal outcome and is treated as one: what succeeded is committed,
 * what failed is reported by document number.
 */
class BulkTransitionController extends Controller
{
    /** Enough for a morning's queue; beyond this the user wants a filter, not a bigger button. */
    private const MAX = 100;

    public function __invoke(Request $request, string $resource): RedirectResponse
    {
        [$documents, $allowed, $noun, $apply] = $this->plan($resource);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer'],
            'to' => ['required', 'string', Rule::in($allowed)],
        ]);

        $done = 0;
        $failures = [];

        foreach ($documents($data['ids']) as $document) {
            try {
                // Each in its own transaction: one refusal must not roll back the others.
                DB::transaction(fn () => $apply($document, $data['to']));
                $done++;
            } catch (TransitionDenied $e) {
                $failures[] = ($document->getAttribute('number') ?? '#'.$document->getKey()).' — '.$e->getMessage();
            }
        }

        $label = $done === 1 ? $noun : $noun.'s';
        $message = $done > 0 ? "{$done} {$label} moved to {$data['to']}." : 'Nothing was changed.';

        if ($failures !== []) {
            // Named, not counted: "3 failed" tells a manager nothing they can act on.
            return back()->with(
                $done > 0 ? 'warning' : 'error',
                $message.' '.count($failures).' refused: '.implode(' · ', $failures),
            );
        }

        return back()->with('success', $message);
    }

    /**
     * @return array{
     *     0: callable(list<int>): iterable<Model>,
     *     1: list<string>,
     *     2: string,
     *     3: callable(Model, string): void
     * }
     */
    private function plan(string $resource): array
    {
        return match ($resource) {
            'purchase-requisitions' => [
                fn (array $ids): iterable => PurchaseRequisition::query()->whereIn('id', $ids)->get(),
                ['submitted', 'approved', 'rejected'],
                'requisition',
                function (Model $document, string $to): void {
                    /** @var PurchaseRequisition $document */
                    app(PurchaseRequisitionStateMachine::class)->transition($document, $to);
                },
            ],
            'purchase-orders' => [
                fn (array $ids): iterable => PurchaseOrder::query()->whereIn('id', $ids)->get(),
                ['pending_approval', 'approved', 'sent'],
                'purchase order',
                function (Model $document, string $to): void {
                    /** @var PurchaseOrder $document */
                    app(PurchaseOrderStateMachine::class)->transition($document, $to);
                },
            ],
            default => abort(404, 'Nothing bulk-transitionable by that name.'),
        };
    }
}
