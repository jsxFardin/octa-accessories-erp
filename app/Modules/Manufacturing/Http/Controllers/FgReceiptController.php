<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\FgReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * P0-3 — post a production-driven FG receipt from the job card screen.
 *
 * Thin on purpose: eligibility, ceiling, QC state, lot creation and the ledger movement all
 * live in FgReceiptService, inside one transaction.
 */
class FgReceiptController extends Controller
{
    public function __construct(private readonly FgReceiptService $receipts) {}

    public function store(Request $request, JobCard $jobCard): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'grade' => ['required', Rule::in(['A', 'B', 'reject'])],
            // Generated when the form mounts; a double-submit replays instead of double-posting.
            'client_ref' => ['required', 'string', 'max:64'],
        ]);

        try {
            $receipt = $this->receipts->post(
                $jobCard,
                (float) $data['qty'],
                (int) $data['warehouse_id'],
                $data['client_ref'],
                $data['grade'],
                userId: $request->user()->id,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $note = $receipt->lot_id !== null
            ? "Receipt {$receipt->number} posted. Lot created — ".
              ($receipt->grade === 'reject' || $receipt->qc_inspection_id === null
                  ? 'held in quarantine until final QC accepts.'
                  : 'available for packing.')
            : "Receipt {$receipt->number} posted.";

        return back()->with('success', $note);
    }
}
