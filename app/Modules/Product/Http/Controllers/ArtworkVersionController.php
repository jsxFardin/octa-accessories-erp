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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArtworkVersionController extends Controller
{
    /**
     * Mirrors the artwork_versions_format_chk check constraint. Anything outside this list
     * is stored as NULL rather than crashing the insert.
     */
    private const FORMATS = ['ai', 'eps', 'pdf', 'cdr', 'psd', 'png', 'jpg', 'svg'];

    /** A JPEG arrives under either spelling; the column stores only one. */
    private const FORMAT_ALIASES = ['jpeg' => 'jpg'];

    /**
     * What to tell the browser a stored file is. The disk guesses from the *stored* name,
     * which is a random hash with no extension at all, so every preview arrived as
     * `application/octet-stream` and downloaded instead of rendering.
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ai' => 'application/postscript',
        'eps' => 'application/postscript',
        'psd' => 'image/vnd.adobe.photoshop',
        'cdr' => 'application/vnd.corel-draw',
    ];

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
                'file_format' => $this->fileFormat($file),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', "Version {$version->version_no} uploaded.");
    }

    /**
     * The file itself, streamed from the private disk.
     *
     * Artwork is a customer's intellectual property and is stored on `local`, which has no
     * public URL by design. Until this existed the screen could say a file had been uploaded
     * and show its path and its checksum, but nobody could look at the thing they were being
     * asked to approve — a sign-off on a filename is not a sign-off.
     *
     * Inline by default so a browser renders an image or a PDF in place; `?download=1` sends
     * the same bytes as an attachment for the formats a browser cannot draw (AI, EPS, CDR,
     * PSD).
     */
    public function file(Request $request, ArtworkVersion $version): StreamedResponse
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($version->file_path), 404, 'The file for this version is missing from storage.');

        $filename = $version->artwork->code.'-v'.$version->version_no
            .($version->file_format === null ? '' : '.'.$version->file_format);

        return $request->boolean('download')
            ? $disk->download($version->file_path, $filename)
            : $disk->response($version->file_path, $filename, [
                'Content-Type' => self::MIME_TYPES[$version->file_format] ?? 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
                // An uploaded SVG is markup, and markup served inline from this origin can
                // carry script. Sandboxed and un-sniffable, it can only draw itself.
                'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ]);
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

    /**
     * The mimes rule checks the extension guessed from the file's contents, not the one in
     * the uploaded filename, so the client extension can be any string at all — including
     * one the check constraint rejects, which turned a bad filename into a 500.
     */
    private function fileFormat(UploadedFile $file): ?string
    {
        $format = strtolower($file->getClientOriginalExtension());
        $format = self::FORMAT_ALIASES[$format] ?? $format;

        return in_array($format, self::FORMATS, true) ? $format : null;
    }
}
