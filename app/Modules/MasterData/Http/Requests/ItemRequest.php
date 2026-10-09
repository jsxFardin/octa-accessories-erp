<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Requests;

use App\Support\Reference\ItemVocabulary;
use App\Support\Validation\FamilyAttributeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $itemId = $this->route('item')?->id;
        $type = (string) $this->input('item_type', 'raw_material');
        $buy = $this->input('make_or_buy', 'buy') === 'buy';

        return [
            'item_category_id' => ['required', 'integer', 'exists:item_categories,id'],
            // Typed for catalogues that already have one; left empty, the family's series assigns it.
            'code' => ['nullable', 'string', 'max:40', Rule::unique('items', 'code')->ignore($itemId)],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'item_type' => ['required', Rule::in(array_keys(ItemVocabulary::ITEM_TYPES))],
            'make_or_buy' => ['required', Rule::in(array_keys(ItemVocabulary::MAKE_OR_BUY))],
            'production_family_id' => [$buy ? 'nullable' : 'required', 'integer', 'exists:production_families,id'],
            'item_group_id' => ['nullable', 'integer', Rule::exists('item_groups', 'id')->where('production_family_id', (int) $this->input('production_family_id'))],
            'garment_type' => ['nullable', Rule::in(array_keys(ItemVocabulary::GARMENT_TYPES))],
            'spec_scope' => ['required', Rule::in(array_keys(ItemVocabulary::SPEC_SCOPES))],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id', Rule::requiredIf($this->input('spec_scope') === 'buyer')],
            'material_base' => ['nullable', Rule::in(array_keys(ItemVocabulary::MATERIAL_BASES))],
            'variant_axes' => ['array'],
            'variant_axes.*' => [Rule::in(array_keys(ItemVocabulary::VARIANT_AXES))],
            'base_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'purchase_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'order_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'pack_pcs_per_inner' => ['nullable', 'integer', 'min:1'],
            'pack_inners_per_carton' => ['nullable', 'integer', 'min:1'],
            'default_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'min_order_qty' => ['numeric', 'min:0'],
            // BR-25 divides by this, so zero is not merely odd — it is a division by zero.
            'order_multiple' => ['numeric', 'gt:0'],
            'reorder_level' => ['numeric', 'min:0'],
            'max_stock_qty' => ['nullable', 'numeric', 'gte:reorder_level'],
            'safety_days' => ['integer', 'min:0', 'max:365'],
            'std_rate' => ['numeric', 'min:0'],
            'valuation_method' => ['required', Rule::in(array_keys(ItemVocabulary::VALUATION_METHODS))],
            'standard_wastage_pct' => ['numeric', 'min:0'],
            'density' => ['nullable', 'numeric', 'gt:0'],
            'gsm' => ['nullable', 'numeric', 'gt:0'],
            // BR-10 reads this; the process default applies when it is null.
            'ink_lay_gsm' => ['nullable', 'numeric', 'gt:0'],
            'shade_code' => ['nullable', 'string', 'max:40'],
            'is_lot_tracked' => ['boolean'],
            'is_shade_critical' => ['boolean'],
            'has_expiry' => ['boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'required_if:has_expiry,true'],
            'tool_owner_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'tool_cavities' => ['nullable', 'integer', 'min:1', Rule::requiredIf($type === 'tool')],
            'service_charge_basis' => ['nullable', Rule::in(array_keys(ItemVocabulary::CHARGE_BASES)), Rule::requiredIf($type === 'service')],
            'qc_plan_ref' => ['nullable', 'string', 'max:80'],
            'inventory_account' => ['nullable', 'string', 'max:40'],
            'cogs_account' => ['nullable', 'string', 'max:40'],
            'attributes' => ['array'],
            // The family's own specification fields, read from Setup.
            ...FamilyAttributeRules::for($this->familyId()),
        ];
    }

    private function familyId(): ?int
    {
        $id = $this->input('production_family_id');

        return $id === null || $id === '' ? null : (int) $id;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'order_multiple.gt' => 'The order multiple must be greater than zero. Purchase quantities are rounded up to it.',
            'shelf_life_days.required_if' => 'A material that expires needs a shelf life, or its expiry date cannot be worked out.',
            'production_family_id.required' => 'A made item belongs to a production family.',
            'item_group_id.exists' => 'Choose a group that belongs to the chosen family.',
            'customer_id.required' => 'A buyer-specific item needs its buyer.',
            'tool_cavities.required' => 'A tool needs its cavity count.',
            'service_charge_basis.required' => 'A service needs a charge basis.',
            'max_stock_qty.gte' => 'The maximum stock cannot be below the reorder level.',
            ...FamilyAttributeRules::messages($this->familyId()),
        ];
    }

    protected function prepareForValidation(): void
    {
        // A field the form hid (the order multiple of a made item) arrives blank; blank is the
        // default, not a wrong number.
        $defaults = ['min_order_qty' => 0, 'order_multiple' => 1, 'reorder_level' => 0, 'safety_days' => 0, 'std_rate' => 0, 'standard_wastage_pct' => 0];

        foreach ($defaults as $key => $default) {
            if ($this->has($key) && blank($this->input($key))) {
                $this->merge([$key => $default]);
            }
        }

        $this->mergeIfMissing([
            'item_type' => 'raw_material',
            'make_or_buy' => 'buy',
            'spec_scope' => 'standard',
            'variant_axes' => [],
            'min_order_qty' => 0,
            'order_multiple' => 1,
            'reorder_level' => 0,
            'safety_days' => 0,
            'std_rate' => 0,
            'valuation_method' => 'weighted_average',
            'standard_wastage_pct' => 0,
            'is_lot_tracked' => true,
            'is_shade_critical' => false,
            'has_expiry' => false,
            'attributes' => [],
        ]);
    }
}
