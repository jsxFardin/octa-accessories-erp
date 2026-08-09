<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\BomLine;
use App\Modules\Product\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    /**
     * BOM quantities are per `base_qty` finished pieces — 1000 by default (BR-1).
     *
     * `formula_ref` marks a line whose quantity is *derived* rather than fixed: MRP recomputes
     * those from the spec instead of trusting the stored number, so a spec revision does not
     * silently leave the BOM wrong (02-database-schema §3.3).
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'product_spec_id' => ['nullable', 'integer', 'exists:product_specs,id'],
            'base_qty' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty_per_base' => ['required', 'numeric', 'gt:0'],
            'lines.*.wastage_pct' => ['nullable', 'numeric', 'min:0'],
            'lines.*.colour_index' => ['nullable', 'integer', 'min:1'],
            'lines.*.is_optional' => ['nullable', 'boolean'],
            'lines.*.formula_ref' => ['nullable', 'string', 'max:20'],
        ]);

        $bom = DB::transaction(function () use ($product, $data, $request): Bom {
            $bom = Bom::query()->create([
                'product_id' => $product->id,
                'product_spec_id' => $data['product_spec_id'] ?? $product->currentSpec?->id,
                'version_no' => (int) $product->boms()->max('version_no') + 1,
                'status' => Bom::DRAFT,
                'base_qty' => $data['base_qty'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                BomLine::query()->create([
                    'bom_id' => $bom->id,
                    'item_id' => $line['item_id'],
                    'uom_id' => $line['uom_id'],
                    'qty_per_base' => $line['qty_per_base'],
                    'wastage_pct' => $line['wastage_pct'] ?? 0,
                    'colour_index' => $line['colour_index'] ?? null,
                    'is_optional' => $line['is_optional'] ?? false,
                    'formula_ref' => $line['formula_ref'] ?? null,
                ]);
            }

            return $bom;
        });

        return back()->with('success', "BOM v{$bom->version_no} created as a draft.");
    }

    /**
     * PD-3 — exactly one BOM per product is active. Same emulation as P2 and A2: supersede
     * the outgoing version first, or the unique index over `active_key` rejects the write.
     */
    public function activate(Bom $bom): RedirectResponse
    {
        DB::transaction(function () use ($bom): void {
            Bom::query()
                ->where('product_id', $bom->product_id)
                ->where('id', '!=', $bom->getKey())
                ->where('status', Bom::ACTIVE)
                ->update(['status' => Bom::SUPERSEDED]);

            $bom->update(['status' => Bom::ACTIVE]);
        });

        return back()->with('success', "BOM v{$bom->version_no} is now active.");
    }
}
