<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Modules\MasterData\Models\FamilyAttribute;
use Illuminate\Validation\Rule;

/**
 * The validation rules for a family's specification attributes, read from `family_attributes`
 * so an administrator who adds "tip type" to cords in Setup gets the field asked for and
 * checked without a release.
 *
 * Values travel under `attributes.<key>` on the request and are stored as that JSON bag.
 */
class FamilyAttributeRules
{
    /**
     * @return array<string, list<mixed>> rules keyed `attributes.<key>`
     */
    public static function for(?int $familyId): array
    {
        if ($familyId === null) {
            return [];
        }

        $rules = [];

        foreach (FamilyAttribute::query()->where('production_family_id', $familyId)->where('is_active', true)->get() as $attribute) {
            $rule = [$attribute->is_required ? 'required' : 'nullable'];

            $rule[] = match ($attribute->data_type) {
                'number' => 'numeric',
                'boolean' => 'boolean',
                'select' => Rule::in($attribute->options ?? []),
                default => 'string',
            };

            if ($attribute->data_type === 'text') {
                $rule[] = 'max:120';
            }

            $rules["attributes.{$attribute->attr_key}"] = $rule;
        }

        return $rules;
    }

    /**
     * @return array<string, string> messages keyed `attributes.<key>.required`
     */
    public static function messages(?int $familyId): array
    {
        if ($familyId === null) {
            return [];
        }

        $messages = [];

        foreach (FamilyAttribute::query()->where('production_family_id', $familyId)->where('is_required', true)->get() as $attribute) {
            $messages["attributes.{$attribute->attr_key}.required"] = "{$attribute->label} is part of this family's specification.";
        }

        return $messages;
    }

    /**
     * The attribute definitions a form renders, grouped by family id.
     *
     * @return array<int, list<array{key: string, label: string, type: string, unit: string|null, options: list<string>|null, required: bool}>>
     */
    public static function definitions(): array
    {
        $grouped = [];

        foreach (FamilyAttribute::query()->where('is_active', true)->orderBy('sort_order')->get() as $attribute) {
            $grouped[$attribute->production_family_id][] = [
                'key' => $attribute->attr_key,
                'label' => $attribute->label,
                'type' => $attribute->data_type,
                'unit' => $attribute->unit,
                'options' => $attribute->options,
                'required' => $attribute->is_required,
            ];
        }

        return $grouped;
    }
}
