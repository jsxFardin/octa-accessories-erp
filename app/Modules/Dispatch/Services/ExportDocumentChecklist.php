<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Services;

use App\Modules\Dispatch\Models\ExportShipment;
use Illuminate\Support\Facades\DB;

/**
 * DF-6 AC3 — which documents a shipment needs before it can be treated as complete, derived
 * from the shipment rather than configured: the mode decides the transport document, the
 * credit decides whether the LC set is among them, the buyer's bonded status decides the UD,
 * the terms decide insurance. Commercial invoice, packing list, certificate of origin and the
 * EXP form go with every export from Bangladesh.
 */
class ExportDocumentChecklist
{
    /** How each document is said on screen. */
    public const LABELS = [
        'commercial_invoice' => 'Commercial invoice',
        'packing_list' => 'Packing list',
        'coo' => 'Certificate of origin',
        'exp_form' => 'EXP form',
        'bl' => 'Bill of lading',
        'awb' => 'Air waybill',
        'ud' => 'Utilisation declaration (UD)',
        'lc_document' => 'LC document set',
        'insurance' => 'Insurance certificate',
        'other' => 'Other',
    ];

    public const TYPES = ['commercial_invoice', 'packing_list', 'coo', 'exp_form', 'bl', 'awb', 'ud', 'lc_document', 'insurance', 'other'];

    /** @return list<string> */
    public function required(ExportShipment $shipment): array
    {
        $types = ['commercial_invoice', 'packing_list', 'coo', 'exp_form'];

        $types[] = match ($shipment->mode) {
            'air' => 'awb',
            'sea' => 'bl',
            default => null,
        };

        if ($shipment->lc_id !== null) {
            $types[] = 'lc_document';
        }

        if ((bool) DB::table('customers')->where('id', $shipment->customer_id)->value('is_bonded')) {
            $types[] = 'ud';
        }

        if (in_array(strtoupper((string) $shipment->incoterm), ['CIF', 'CIP'], true)) {
            $types[] = 'insurance';
        }

        return array_values(array_filter($types));
    }

    /** @return list<string> */
    public function present(ExportShipment $shipment): array
    {
        return DB::table('export_documents')->where('export_shipment_id', $shipment->id)->distinct()->pluck('doc_type')->values()->all();
    }

    /** @return list<string> */
    public function missing(ExportShipment $shipment): array
    {
        return array_values(array_diff($this->required($shipment), $this->present($shipment)));
    }

    /** @return list<array{type: string, label: string, present: bool, required: bool}> */
    public function rows(ExportShipment $shipment): array
    {
        $required = $this->required($shipment);
        $present = $this->present($shipment);
        $rows = [];

        foreach (array_unique([...$required, ...$present]) as $type) {
            $rows[] = [
                'type' => $type,
                'label' => self::LABELS[$type] ?? $type,
                'present' => in_array($type, $present, true),
                'required' => in_array($type, $required, true),
            ];
        }

        return $rows;
    }
}
