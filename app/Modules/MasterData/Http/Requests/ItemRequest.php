<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $itemId = $this->route('item')?->id;

        return [
            'item_category_id' => ['required', 'integer', 'exists:item_categories,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('items', 'code')->ignore($itemId)],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'base_uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'purchase_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'default_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'min_order_qty' => ['numeric', 'min:0'],
            // BR-25 divides by this, so zero is not merely odd — it is a division by zero.
            'order_multiple' => ['numeric', 'gt:0'],
            'reorder_level' => ['numeric', 'min:0'],
            'safety_days' => ['integer', 'min:0', 'max:365'],
            'std_rate' => ['numeric', 'min:0'],
            'density' => ['nullable', 'numeric', 'gt:0'],
            'gsm' => ['nullable', 'numeric', 'gt:0'],
            // BR-10 reads this; the process default applies when it is null.
            'ink_lay_gsm' => ['nullable', 'numeric', 'gt:0'],
            'shade_code' => ['nullable', 'string', 'max:40'],
            'is_lot_tracked' => ['boolean'],
            'is_shade_critical' => ['boolean'],
            'has_expiry' => ['boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'required_if:has_expiry,true'],
            'attributes' => ['array'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'order_multiple.gt' => 'The order multiple must be greater than zero — BR-25 rounds purchase quantities up to it.',
            'shelf_life_days.required_if' => 'An item that expires needs a shelf life, otherwise BR-39 cannot compute an expiry date.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'min_order_qty' => 0,
            'order_multiple' => 1,
            'reorder_level' => 0,
            'safety_days' => 0,
            'std_rate' => 0,
            'is_lot_tracked' => true,
            'is_shade_critical' => false,
            'has_expiry' => false,
            'is_active' => true,
            'attributes' => [],
        ]);
    }
}
