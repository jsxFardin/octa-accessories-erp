<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\ProductionFamily;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\Routing;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Support\Facades\DB;

/**
 * The one way an item is written.
 *
 * An item and its make profile are two rows that mean one thing, so they are created in one
 * transaction with the item code allocated inside it (BR-34). Controllers, imports and
 * seeders all come through here; nothing else inserts into `products`.
 */
class ItemMasterService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /**
     * @param  array<string, mixed>  $item  columns of `items`; `code` is allocated when absent
     * @param  array<string, mixed>  $profile  columns of `products`, used when the item is made
     */
    public function create(array $item, array $profile = [], ?int $userId = null): Item
    {
        return DB::transaction(function () use ($item, $profile, $userId): Item {
            $item = $this->withDefaults($item);
            $item['created_by'] = $item['created_by'] ?? $userId;

            if (($item['code'] ?? '') === '') {
                $family = isset($item['production_family_id'])
                    ? ProductionFamily::query()->find($item['production_family_id'])
                    : null;

                $item['code'] = $this->numbers->nextItemCode($family?->code_prefix ?? 'ITM', $family?->code ?? '00');
            }

            /** @var Item $model */
            $model = Item::query()->create($item);

            if ($model->isMade()) {
                // A buyer-specific made item is made for that buyer: the profile carries it.
                $profile['customer_id'] ??= $item['customer_id'] ?? null;
                $this->writeProfile($model, $profile, $userId);
            }

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $profile
     */
    public function update(Item $item, array $item_data, array $profile = []): Item
    {
        return DB::transaction(function () use ($item, $item_data, $profile): Item {
            unset($item_data['code'], $item_data['status']);

            $item->update($item_data);
            $item->refresh();

            if ($item->isMade()) {
                // P1 — a buyer, once set on the product, stays; a first buyer may be named here.
                if ($item->product?->customer_id === null && ! empty($item_data['customer_id'])) {
                    $profile['customer_id'] ??= $item_data['customer_id'];
                }

                $this->writeProfile($item, $profile, null);
            }

            return $item;
        });
    }

    /**
     * Seeders and imports address an item by code: create it, or bring the one that carries
     * that code up to date.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $profile
     */
    public function upsertByCode(string $code, array $item, array $profile = [], ?int $userId = null): Item
    {
        $existing = Item::query()->withTrashed()->where('code', $code)->first();

        if ($existing === null) {
            return $this->create([...$item, 'code' => $code], $profile, $userId);
        }

        return DB::transaction(function () use ($existing, $item, $profile): Item {
            $existing->update($this->withDefaults($item));
            $existing->refresh();

            if ($existing->isMade()) {
                $this->writeProfile($existing, $profile, null);
            }

            return $existing;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withDefaults(array $item): array
    {
        $item['item_type'] ??= 'raw_material';
        $item['make_or_buy'] ??= in_array($item['item_type'], ['finished_good', 'semi_finished', 'component'], true) ? Item::MAKE : Item::BUY;
        $item['spec_scope'] ??= 'standard';
        $item['variant_axes'] ??= [];
        $item['attributes'] ??= [];
        $item['valuation_method'] ??= 'weighted_average';

        // A made item without a category lands in the one its type names.
        if (! isset($item['item_category_id']) && $item['make_or_buy'] === Item::MAKE) {
            $item['item_category_id'] = ItemCategory::query()
                ->where('item_class', $item['item_type'] === 'finished_good' ? 'finished_good' : ($item['item_type'] === 'component' ? 'component' : 'semi_finished'))
                ->value('id');
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function writeProfile(Item $item, array $profile, ?int $userId): Product
    {
        /** @var Product|null $product */
        $product = Product::query()->withTrashed()->where('item_id', $item->getKey())->first();

        if ($product !== null) {
            $product->update($profile);

            return $product;
        }

        $profile['product_type'] ??= 'other';

        // A product with no routing is costed with no machine time, and nothing said so. The
        // family's default routing is what was going to be picked anyway (spec §2); a label
        // type's default stands in for the label families the geometry calculators know.
        $profile['routing_id'] ??= ($profile['product_type'] !== 'other'
            ? Routing::query()->where('product_type', $profile['product_type'])->where('is_active', true)->where('is_default', true)->value('id')
            : null)
            ?? ($item->production_family_id === null ? null : Routing::query()
                ->where('production_family_id', $item->production_family_id)
                ->whereNull('product_type')
                ->where('is_active', true)
                ->where('is_default', true)
                ->value('id'));

        /** @var Product $product */
        $product = Product::query()->create([
            ...$profile,
            'item_id' => $item->getKey(),
            'created_by' => $profile['created_by'] ?? $userId,
        ]);

        return $product;
    }
}
