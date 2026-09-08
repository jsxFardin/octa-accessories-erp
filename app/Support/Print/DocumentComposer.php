<?php

declare(strict_types=1);

namespace App\Support\Print;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Organisation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns a registry key and an id into everything a document view needs.
 *
 * The three checks that must never be skipped happen here, once, for every document: the caller
 * holds the resource's `view` right, the document is in a state fit to be seen outside, and the
 * fact that it left is written to `audit_logs`. Printing is an audited event for the same reason
 * an export is — a quotation on a customer's desk is a copy of the data that walked out of the
 * building, and `printed` exists in `audit_logs_event_chk` for exactly this.
 */
class DocumentComposer
{
    public function __construct(
        private readonly Organisation $organisation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{view: string, orientation: string, filename: string, data: array<string, mixed>}
     */
    public function compose(string $key, int $id, ?User $user, bool $forPdf): array
    {
        $definition = DocumentRegistry::find($key);

        if ($definition === null) {
            abort(404);
        }

        abort_unless($user?->hasPermission($definition['permission']) === true, 403);

        /** @var callable(int): array<string, mixed> $load */
        $load = $definition['load'];
        $data = $load($id);

        /** @var object $document */
        $document = $data['document'];

        $status = isset($document->status) ? (string) $document->status : '';

        abort_if(
            $status !== '' && in_array($status, $definition['withheld'], true),
            403,
            $definition['withheld_reason'],
        );

        $this->audit->recordTable($definition['table'], $id, 'printed');

        $number = isset($document->number) && $document->number !== ''
            ? (string) $document->number
            : null;

        $organisation = $this->letterhead($forPdf);

        return [
            'view' => $definition['view'],
            'orientation' => $definition['orientation'],
            'filename' => $this->filename($definition['label'], $number, $id),
            'data' => [
                'organisation' => $organisation,
                'documentLabel' => $definition['label'],
                'pdf' => $forPdf,
                // Offered on screen only: the toolbar it belongs to is not in the PDF.
                'pdfUrl' => $forPdf ? null : route("{$key}.pdf", ['id' => $id]),
            ] + $this->formatters($organisation) + $data,
        ];
    }

    /**
     * The four formatters every document needs, resolved from the organisation profile once.
     *
     * Passed in rather than repeated as an `@php` block at the top of twelve views, because a
     * date format that is right on the invoice and wrong on the challan is the kind of
     * inconsistency nobody reports and everybody notices.
     *
     * @param  array<string, mixed>  $organisation
     * @return array<string, \Closure>
     */
    private function formatters(array $organisation): array
    {
        $dateFormat = (string) $organisation['date_format'];
        $timezone = (string) $organisation['timezone'];

        return [
            'fmtDate' => fn (mixed $value): string => $value === null || $value === ''
                ? '—'
                : Carbon::parse((string) $value)->format($dateFormat),

            'fmtDateTime' => fn (mixed $value): string => $value === null || $value === ''
                ? '—'
                : Carbon::parse((string) $value)->setTimezone($timezone)->format($dateFormat.' H:i'),

            // Two decimals on money, always. A total that renders as `1250.5` on a document
            // somebody is going to pay against reads as an error.
            'money' => fn (mixed $value): string => number_format((float) $value, 2),

            // Quantities are whole pieces in this factory — 50,000 labels, not 50,000.000000 —
            // so the stored six decimals are dropped unless they carry something.
            'qty' => function (mixed $value): string {
                $number = (float) $value;

                return number_format($number, $number === floor($number) ? 0 : 3);
            },
        ];
    }

    /**
     * The letterhead, with one adjustment for dompdf.
     *
     * On screen the logo is a URL the browser fetches. dompdf fetches nothing — remote access is
     * off, and rightly so — so it needs the file on disk instead. A missing file becomes no logo
     * rather than a broken image box on a customer's invoice.
     *
     * @return array<string, mixed>
     */
    private function letterhead(bool $forPdf): array
    {
        $organisation = $this->organisation->forFrontend();

        if (! $forPdf || ! is_string($organisation['logo_url'])) {
            return $organisation;
        }

        $local = public_path(ltrim($organisation['logo_url'], '/'));
        $organisation['logo_url'] = is_file($local) ? $local : null;

        return $organisation;
    }

    /**
     * `invoice-INV-2026-00041.pdf`, not `document.pdf`.
     *
     * A filing clerk who has downloaded forty of these needs to tell them apart in a folder
     * listing, so the document number carries into the name. An unnumbered draft falls back to
     * the id, which is at least unique.
     */
    private function filename(string $label, ?string $number, int $id): string
    {
        $stem = Str::slug($label).'-'.($number !== null ? Str::slug($number) : "draft-{$id}");

        return $stem.'.pdf';
    }
}
