<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * The label that goes on a roll, a cone or a carton when it is put into the store.
 *
 * Every lot has carried a barcode value since the first receipt, and every screen called a lot
 * "barcoded" — but nothing ever printed one, so issues, transfers and counts were all done by
 * reading a lot number off a list and picking it from a dropdown. This turns the value into a
 * Code 128 barcode a handheld scanner reads, with the words a person needs beside it.
 */
final class LotLabels
{
    /**
     * @param  list<int>  $lotIds
     * @return Collection<int, covariant array<string, mixed>>
     */
    public function for(array $lotIds): Collection
    {
        if ($lotIds === []) {
            return collect();
        }

        $generator = new BarcodeGeneratorSVG;

        return DB::table('stock_lots as sl')
            ->leftJoin('items as i', 'i.id', '=', 'sl.item_id')
            ->leftJoin('products as p', 'p.id', '=', 'sl.product_id')
            ->leftJoin('items as pi', 'pi.id', '=', 'p.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'sl.uom_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->whereIn('sl.id', $lotIds)
            ->orderBy('sl.lot_no')
            ->get([
                'sl.id', 'sl.lot_no', 'sl.barcode', 'sl.balance_qty', 'sl.shade_code', 'sl.roll_length_m',
                'sl.supplier_batch_no', 'sl.received_on', 'sl.expiry_date', 'sl.cert_scheme',
                'i.code as item_code', 'i.name as item_name', 'pi.code as product_code', 'pi.name as product_name',
                'u.code as uom', 'w.code as warehouse',
            ])
            ->map(function (object $lot) use ($generator): array {
                $value = (string) ($lot->barcode ?: $lot->lot_no);

                return [
                    'lot_no' => $lot->lot_no,
                    'code' => $lot->item_code ?? $lot->product_code,
                    'name' => $lot->item_name ?? $lot->product_name,
                    'qty' => rtrim(rtrim(number_format((float) $lot->balance_qty, 3, '.', ','), '0'), '.'),
                    'uom' => $lot->uom,
                    'warehouse' => $lot->warehouse,
                    'shade' => $lot->shade_code,
                    'roll_length' => $lot->roll_length_m === null
                        ? null
                        : rtrim(rtrim(number_format((float) $lot->roll_length_m, 2, '.', ','), '0'), '.').' m',
                    'batch' => $lot->supplier_batch_no,
                    'received_on' => $lot->received_on === null ? null : Carbon::parse($lot->received_on)->format('d M Y'),
                    'expiry_date' => $lot->expiry_date === null ? null : Carbon::parse($lot->expiry_date)->format('d M Y'),
                    'scheme' => $lot->cert_scheme,
                    // Two-unit-wide bars: narrow enough for a 70 mm label, wide enough for the
                    // cheap handheld readers a store actually has.
                    'barcode_svg' => $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 46),
                    'barcode_value' => $value,
                ];
            });
    }
}
