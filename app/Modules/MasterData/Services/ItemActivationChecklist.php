<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\FamilyAttribute;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\UomConversion;
use App\Modules\Product\Services\ProductSetup;
use App\Support\Settings\Settings;
use App\Support\Text\Plain;

/**
 * IM-1 — what an item still needs before it may be made active, in the order the work is
 * done, with the reason beside anything that is not right.
 *
 * The document's rule: before an item is active its family, units, routing and bill of
 * materials are linked, its wastage, warehouse and stock levels are set, it has a QC plan,
 * a standard cost and account mapping, and an approver has said so. The QC plan and the
 * accounts are asked for only when the settings say the factory keeps them yet.
 *
 * A step is `done`, `todo`, `attention` (there, but not usable) or `skipped` (not asked of this
 * kind of item), and `required` says whether it blocks activation.
 */
class ItemActivationChecklist
{
    public function __construct(
        private readonly ProductSetup $productSetup,
        private readonly Settings $settings,
    ) {}

    /**
     * @return list<array{key: string, label: string, state: string, detail: string, required: bool}>
     */
    public function steps(Item $item): array
    {
        $item->loadMissing(['family', 'group', 'baseUom', 'orderUom', 'purchaseUom', 'defaultWarehouse', 'product']);

        $made = $item->isMade();
        $productSteps = $made && $item->product !== null
            ? collect($this->productSetup->steps($item->product))->keyBy('key')
            : collect();

        $steps = [
            $this->classification($item),
            $this->units($item),
            $this->specification($item),
        ];

        if ($made) {
            $steps[] = $this->passThrough($productSteps->get('bom'), 'bom', 'Bill of materials', 'What it is made of; costing and material issue read it.');
            $steps[] = $this->passThrough($productSteps->get('routing'), 'routing', 'Routing', 'How it is made; machine time is costed from it.');
        }

        $steps[] = $this->wastage($item, $made);
        $steps[] = $this->warehouse($item);
        $steps[] = $this->stockLevels($item, $made);
        $steps[] = $this->qcPlan($item);
        $steps[] = $this->standardCost($item, $made, $productSteps->get('price'));
        $steps[] = $this->accounts($item);
        $steps[] = $this->approver();

        return $steps;
    }

    /**
     * The required steps not yet done — what the activation gate refuses on.
     *
     * @return list<string>
     */
    public function blockers(Item $item): array
    {
        $blockers = [];

        foreach ($this->steps($item) as $step) {
            if ($step['required'] && $step['state'] !== 'done' && $step['state'] !== 'skipped') {
                $blockers[] = $step['label'].': '.rtrim($step['detail'], '.');
            }
        }

        return $blockers;
    }

    /** @return array<string, mixed> */
    private function classification(Item $item): array
    {
        $step = ['key' => 'classification', 'label' => 'Classification', 'required' => true];

        if ($item->isMade() && $item->production_family_id === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'No production family. A made item belongs to one of the ten.'];
        }

        if ($item->item_group_id === null && $item->production_family_id !== null) {
            return [...$step, 'state' => 'todo', 'detail' => "No group under {$item->family?->name}."];
        }

        if ($item->isMade() && $item->garment_type === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'No garment type: knit, woven or both.'];
        }

        $family = $item->family !== null ? "{$item->family->code} {$item->family->name}" : 'No family (bought material)';
        $group = $item->group !== null ? " › {$item->group->name}" : '';

        return [...$step, 'state' => 'done', 'detail' => "{$family}{$group}."];
    }

    /** @return array<string, mixed> */
    private function units(Item $item): array
    {
        $step = ['key' => 'units', 'label' => 'Units', 'required' => true];
        $base = $item->baseUom?->code ?? '?';

        if ($item->isFinishedGood() && $item->order_uom_id === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'No order unit. A finished good says what the buyer orders it in.'];
        }

        if ($item->isFinishedGood() && ($item->pack_pcs_per_inner === null || $item->pack_inners_per_carton === null)) {
            return [...$step, 'state' => 'todo', 'detail' => 'No pack standard: pieces per inner and inners per carton.'];
        }

        foreach ([$item->order_uom_id => $item->orderUom, $item->purchase_uom_id => $item->purchaseUom] as $other) {
            if ($other === null || $other->id === $item->base_uom_id) {
                continue;
            }

            if (! $this->conversionExists($item, (int) $other->id)) {
                return [...$step, 'state' => 'attention', 'detail' => "1 {$other->code} = how many {$base}? Add the conversion under Setup → UoM conversions."];
            }
        }

        $order = $item->orderUom !== null && $item->order_uom_id !== $item->base_uom_id ? ", ordered in {$item->orderUom->code}" : '';

        return [...$step, 'state' => 'done', 'detail' => "Stocked in {$base}{$order}."];
    }

    private function conversionExists(Item $item, int $uomId): bool
    {
        return UomConversion::query()
            ->where(fn ($q) => $q->where('item_id', $item->getKey())->orWhereNull('item_id'))
            ->where(fn ($q) => $q
                ->where(fn ($pair) => $pair->where('from_uom_id', $uomId)->where('to_uom_id', $item->base_uom_id))
                ->orWhere(fn ($pair) => $pair->where('from_uom_id', $item->base_uom_id)->where('to_uom_id', $uomId)))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function specification(Item $item): array
    {
        $step = ['key' => 'specification', 'label' => 'Family specification', 'required' => true];

        if ($item->production_family_id === null) {
            return [...$step, 'state' => 'skipped', 'detail' => 'No family, so no family attributes to fill in.', 'required' => false];
        }

        $missing = FamilyAttribute::query()
            ->where('production_family_id', $item->production_family_id)
            ->where('is_active', true)
            ->where('is_required', true)
            ->get()
            ->filter(fn (FamilyAttribute $attribute): bool => blank($item->attributes[$attribute->attr_key] ?? null))
            ->pluck('label');

        if ($missing->isNotEmpty()) {
            return [...$step, 'state' => 'todo', 'detail' => 'Not filled in: '.$missing->implode(', ').'.'];
        }

        return [...$step, 'state' => 'done', 'detail' => "The {$item->family?->name} specification is filled in."];
    }

    /**
     * A product setup step, as the activation list shows it.
     *
     * @param  array<string, mixed>|null  $productStep
     * @return array<string, mixed>
     */
    private function passThrough(?array $productStep, string $key, string $label, string $missing): array
    {
        $step = ['key' => $key, 'label' => $label, 'required' => true];

        if ($productStep === null) {
            return [...$step, 'state' => 'todo', 'detail' => $missing];
        }

        return [...$step, 'state' => $productStep['state'], 'detail' => $productStep['detail']];
    }

    /** @return array<string, mixed> */
    private function wastage(Item $item, bool $made): array
    {
        $step = ['key' => 'wastage', 'label' => 'Standard wastage', 'required' => true];

        if ((float) $item->standard_wastage_pct > 0) {
            return [...$step, 'state' => 'done', 'detail' => rtrim(rtrim((string) $item->standard_wastage_pct, '0'), '.').'% over the standard consumption.'];
        }

        // BR-8 — a made item's wastage can be read off its routing when none is typed.
        $routing = $made ? $item->product?->routing : null;

        if ($routing !== null && (float) $routing->totalWastagePct() > 0) {
            return [...$step, 'state' => 'done', 'detail' => rtrim(rtrim((string) $routing->totalWastagePct(), '0'), '.')."% from routing {$routing->code}."];
        }

        if (! $made) {
            return [...$step, 'state' => 'done', 'detail' => 'None: a bought item carries its wastage on the bill that uses it.'];
        }

        return [...$step, 'state' => 'todo', 'detail' => 'No standard wastage, and the routing carries none.'];
    }

    /** @return array<string, mixed> */
    private function warehouse(Item $item): array
    {
        $step = ['key' => 'warehouse', 'label' => 'Default warehouse', 'required' => true];

        return $item->default_warehouse_id === null
            ? [...$step, 'state' => 'todo', 'detail' => 'No default warehouse: where it is received and issued from.']
            : [...$step, 'state' => 'done', 'detail' => "{$item->defaultWarehouse?->code} {$item->defaultWarehouse?->name}."];
    }

    /** @return array<string, mixed> */
    private function stockLevels(Item $item, bool $made): array
    {
        $step = ['key' => 'stock_levels', 'label' => 'Stock levels', 'required' => ! $made];

        if ($made) {
            return [...$step, 'state' => 'skipped', 'detail' => 'Made to order; no minimum or maximum is kept.'];
        }

        if ((float) $item->reorder_level <= 0 && $item->max_stock_qty === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'No reorder level and no maximum, so the material plan cannot say when to buy.'];
        }

        $max = $item->max_stock_qty !== null ? ', maximum '.rtrim(rtrim((string) $item->max_stock_qty, '0'), '.') : '';

        return [...$step, 'state' => 'done', 'detail' => 'Reorder at '.rtrim(rtrim((string) $item->reorder_level, '0'), '.').$max.'.'];
    }

    /** @return array<string, mixed> */
    private function qcPlan(Item $item): array
    {
        $required = $this->settings->bool('item_activation_requires_qc_plan');
        $step = ['key' => 'qc_plan', 'label' => 'QC plan', 'required' => $required];

        if (filled($item->qc_plan_ref)) {
            return [...$step, 'state' => 'done', 'detail' => "Plan {$item->qc_plan_ref}."];
        }

        return $required
            ? [...$step, 'state' => 'todo', 'detail' => 'No QC plan reference.']
            : [...$step, 'state' => 'skipped', 'detail' => 'Not asked for yet: inspection plans are not in use (Settings → Inventory).'];
    }

    /**
     * @param  array<string, mixed>|null  $priceStep
     * @return array<string, mixed>
     */
    private function standardCost(Item $item, bool $made, ?array $priceStep): array
    {
        $step = ['key' => 'standard_cost', 'label' => 'Standard cost', 'required' => true];

        if ((float) $item->std_rate > 0) {
            return [...$step, 'state' => 'done', 'detail' => 'Standard rate on the item.'];
        }

        if ($made && $priceStep !== null && $priceStep['state'] === 'done') {
            return [...$step, 'state' => 'done', 'detail' => 'Costed from its bill of materials and routing.'];
        }

        if ((float) $item->avg_rate > 0) {
            return [...$step, 'state' => 'done', 'detail' => 'Weighted average from receipts.'];
        }

        return [...$step, 'state' => 'todo', 'detail' => $made
            ? 'Cannot be costed yet: see the specification, bill of materials and routing above.'
            : 'No standard rate, and nothing received yet to average.'];
    }

    /** @return array<string, mixed> */
    private function accounts(Item $item): array
    {
        $required = $this->settings->bool('item_activation_requires_accounts');
        $step = ['key' => 'accounts', 'label' => 'Account mapping', 'required' => $required];

        if (filled($item->inventory_account) && filled($item->cogs_account)) {
            return [...$step, 'state' => 'done', 'detail' => "Inventory {$item->inventory_account}, cost of sales {$item->cogs_account}."];
        }

        return $required
            ? [...$step, 'state' => 'todo', 'detail' => 'No inventory or cost-of-sales account.']
            : [...$step, 'state' => 'skipped', 'detail' => 'Not asked for yet: the chart of accounts is not in use (Settings → Inventory).'];
    }

    /** @return array<string, mixed> */
    private function approver(): array
    {
        $allowed = auth()->user()?->hasPermission('item.activate') ?? false;

        return [
            'key' => 'approver',
            'label' => 'Approval',
            'required' => false,
            'state' => $allowed ? 'done' : 'todo',
            'detail' => $allowed
                ? 'You may activate this item.'
                : 'Activation needs someone who may '.Plain::permission('item.activate').'.',
        ];
    }
}
