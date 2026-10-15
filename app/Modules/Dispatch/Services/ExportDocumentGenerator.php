<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Services;

use App\Models\User;
use App\Modules\Dispatch\Models\ExportDocument;
use App\Modules\Dispatch\Models\ExportShipment;
use App\Support\Print\DocumentComposer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * DF-6 AC1 — draft the commercial invoice, packing list or certificate of origin for a shipment
 * from its own data, file the PDF and record it as a drafted document. Running it again
 * replaces the earlier draft: a draft is a starting point, not a record.
 */
class ExportDocumentGenerator
{
    /** Document type → print-registry key. */
    public const GENERATED = [
        'commercial_invoice' => 'export-commercial-invoices',
        'packing_list' => 'export-packing-lists',
        'coo' => 'export-certificates-of-origin',
    ];

    public function __construct(private readonly DocumentComposer $composer) {}

    public function draft(ExportShipment $shipment, string $docType, User $user): ExportDocument
    {
        $key = self::GENERATED[$docType] ?? throw new \InvalidArgumentException("No draft is generated for [{$docType}].");

        $composed = $this->composer->compose($key, (int) $shipment->id, $user, true);

        $pdf = Pdf::loadView($composed['view'], $composed['data'])->setPaper('a4', $composed['orientation']);
        $path = "export-documents/{$shipment->id}/".str_replace('_', '-', $docType).'-'.now()->format('YmdHis').'.pdf';

        Storage::put($path, $pdf->output());

        /** @var ExportDocument|null $existing */
        $existing = ExportDocument::query()
            ->where('export_shipment_id', $shipment->id)
            ->where('doc_type', $docType)
            ->where('status', 'drafted')
            ->first();

        if ($existing !== null && $existing->file_path !== null) {
            Storage::delete($existing->file_path);
        }

        $attributes = [
            'export_shipment_id' => $shipment->id,
            'doc_type' => $docType,
            'doc_no' => $this->number($shipment, $docType),
            'doc_date' => now()->toDateString(),
            'file_path' => $path,
            'status' => 'drafted',
            'remarks' => 'Drafted from the shipment data',
            'created_by' => $user->id,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return ExportDocument::query()->create($attributes);
    }

    private function number(ExportShipment $shipment, string $docType): string
    {
        $stem = $shipment->number ?? "ESH-{$shipment->id}";

        return $stem.'/'.match ($docType) {
            'commercial_invoice' => 'CI',
            'packing_list' => 'PL',
            default => 'COO',
        };
    }
}
