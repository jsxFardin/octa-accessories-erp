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

    public function set(string $key, mixed $value, string $group = 'general', ?string $description = null): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'group_name' => $group,
                'description' => $description,
                'updated_at' => now(),
            ],
        );

        $this->flush();
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function grouped(): array
    {
        $rows = DB::table('settings')->orderBy('group_name')->orderBy('key')->get();
        $grouped = [];

        foreach ($rows as $row) {
            /** @var object{key: string, value: string, group_name: string, description: ?string} $row */
            $grouped[$row->group_name][] = [
                'key' => $row->key,
                'value' => json_decode($row->value, true),
                'description' => $row->description,
            ];
        }

        return $grouped;
    }

    public function flush(): void
    {
        $this->loaded = null;
        cache()->forget(self::CACHE_KEY);
    }
}
