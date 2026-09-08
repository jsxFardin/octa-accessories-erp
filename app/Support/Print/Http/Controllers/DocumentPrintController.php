<?php

declare(strict_types=1);

namespace App\Support\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Print\DocumentComposer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable documents, on paper and as PDF.
 *
 * Blade rather than Inertia: a document wants no JavaScript, no shell and no client-side
 * formatting. The two endpoints render the *same* Blade view, which is the point — the file a
 * customer receives has to be the page a merchandiser saw before sending it, and two renderers
 * that disagree slightly are a support problem waiting to happen. That is also why the shared
 * layout is written to what dompdf understands (tables, not flexbox): the constraint is real, so
 * both outputs live inside it rather than one of them drifting.
 *
 * - `/{resource}/{id}/print` opens the page. The browser prints it, and the browser's own PDF
 *   writer is excellent — this stays the better route for anything a person is about to hold.
 * - `/{resource}/{id}/pdf` returns a file. Needed the moment a document is attached to an email,
 *   filed against an LC, or sent to a brand that will not accept "print this page yourself".
 *
 * What may be printed and by whom is not decided here: see DocumentRegistry and DocumentComposer.
 */
class DocumentPrintController extends Controller
{
    public function __construct(private readonly DocumentComposer $composer) {}

    public function print(Request $request, string $id): View
    {
        $composed = $this->composer->compose($this->documentKey($request), (int) $id, $request->user(), forPdf: false);

        return view($composed['view'], $composed['data']);
    }

    public function pdf(Request $request, string $id): Response
    {
        $composed = $this->composer->compose($this->documentKey($request), (int) $id, $request->user(), forPdf: true);

        $pdf = Pdf::loadView($composed['view'], $composed['data']);
        $pdf->setPaper('a4', $composed['orientation']);

        // Inline rather than as an attachment: the common act is "look at it, then decide", and a
        // browser that opens the file still offers Save. `?download=1` forces the dialogue for
        // the cases where the file is the point.
        return $request->boolean('download')
            ? $pdf->download($composed['filename'])
            : $pdf->stream($composed['filename']);
    }

    /**
     * Which document this route prints, read off the route rather than taken as an argument.
     *
     * The routes are generated per document and carry the registry key as a route default. It
     * cannot be a method parameter: ControllerDispatcher passes route parameters positionally
     * (`array_values`), and a default is appended *after* the parameters parsed from the URI —
     * so `(string $document, string $id)` silently receives them the other way round. That cost
     * an afternoon once; reading it explicitly cannot be got wrong.
     */
    private function documentKey(Request $request): string
    {
        return (string) ($request->route()?->defaults['document'] ?? '');
    }
}
