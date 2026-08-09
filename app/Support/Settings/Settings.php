<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Support\Facades\DB;

/**
 * Business parameters — overhead %, margin floor, tolerances, cut gaps, approval bands —
 * live in the `settings` table and are editable by an admin (08-architecture §8).
 *
 * The dividing line: a rule that may change without a deploy is a setting; a rule that must
 * not change quietly is code with a test. Formulas are code. Their coefficients are settings.
 */
class Settings
{
    private const CACHE_KEY = 'settings:all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function decimal(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        /** @var array<string, mixed> $values */
        $values = cache()->rememberForever(self::CACHE_KEY, function (): array {
            $rows = DB::table('settings')->get(['key', 'value']);
            $values = [];

            foreach ($rows as $row) {
                /** @var object{key: string, value: string} $row */
                $values[$row->key] = json_decode($row->value, true);
            }

            return $values;
        });

        return $this->loaded = $values;
    }

    /**
     * Writing a value must not disturb what the row says about itself.
     *
     * `updateOrInsert` writes every column it is given, so passing the defaults through on an
     * ordinary save flattened every setting into the `general` group and erased every
     * description — which is exactly what happened the first time the settings screen was
     * saved. Group and description are now only written when the caller actually supplies them.
     */
    public function set(string $key, mixed $value, ?string $group = null, ?string $description = null): void
    {
        $attributes = [
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        if ($group !== null) {
            $attributes['group_name'] = $group;
        }

        if ($description !== null) {
            $attributes['description'] = $description;
        }

        $updated = DB::table('settings')->where('key', $key)->update($attributes);

        if ($updated === 0) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $attributes['value'],
                'group_name' => $group ?? 'general',
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->flush();
    }

    /**
     * Settings grouped and labelled for the screen.
     *
     * Grouping and wording come from the catalogue rather than the table: they are code, they
     * are reviewed, and they survive a row whose metadata was lost.
     *
     * @return array<string, array{label: string, settings: list<array<string, mixed>>}>
     */
    public function grouped(): array
    {
        $catalogue = new SettingCatalogue;
        $rows = DB::table('settings')->orderBy('key')->get();
        $grouped = [];

        foreach ($rows as $row) {
            /** @var object{key: string, value: string, group_name: ?string, description: ?string} $row */
            $described = $catalogue->describe($row->key);

            $grouped[$described['group']]['label'] ??= $catalogue->groupLabel($described['group']);
            $grouped[$described['group']]['settings'][] = [
                'key' => $row->key,
                'value' => json_decode($row->value, true),
                'label' => $described['label'],
                'unit' => $described['unit'],
                'hint' => $described['hint'] !== '' ? $described['hint'] : $row->description,
            ];
        }

        // Catalogue order, so Costing is not filed after Visibility because of the alphabet.
        $ordered = [];

        foreach ($catalogue->groupOrder() as $group) {
            if (isset($grouped[$group])) {
                $ordered[$group] = $grouped[$group];
            }
        }

        return $ordered + $grouped;
    }

    public function flush(): void
    {
        $this->loaded = null;
        cache()->forget(self::CACHE_KEY);
    }
}
