<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Artwork;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\States\ArtworkVersionStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ArtworkVersionController extends Controller
{
    public function __construct(private readonly ArtworkVersionStateMachine $states) {}

    /**
     * A1 — versions are numbered contiguously from 1 and never renumbered.
     * A3 — the file is checksummed on upload; that hash is what proves the file the customer
     * approved is the file that reached plate-making.
     */
    public function store(Request $request, Artwork $artwork): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:ai,eps,pdf,cdr,psd,png,jpg,jpeg,svg'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $version = DB::transaction(function () use ($request, $artwork): ArtworkVersion {
            $file = $request->file('file');
            $path = $file->store("artwork/{$artwork->id}", 'local');

            return ArtworkVersion::query()->create([
                'artwork_id' => $artwork->id,
                'version_no' => $artwork->nextVersionNo(),
                'status' => ArtworkVersion::DRAFT,
                'file_path' => $path,
                'file_format' => strtolower($file->getClientOriginalExtension()),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', "Version {$version->version_no} uploaded.");
    }

    /**
     * Every status change goes through the state machine, so the A2 supersede-then-approve
     * ordering, the evidence requirement and the audit row cannot be bypassed.
     */
    public function transition(Request $request, ArtworkVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([
                ArtworkVersion::SUBMITTED,
                ArtworkVersion::APPROVED,
                ArtworkVersion::REJECTED,
            ])],
            'customer_ref' => ['nullable', 'string', 'max:180'],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($version, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $data['to'] === ArtworkVersion::APPROVED
                ? "Version {$version->version_no} approved. It is now the only version production may run against."
                : "Version {$version->version_no} moved to {$data['to']}.",
        );
    }
}
