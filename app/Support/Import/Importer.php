<?php

declare(strict_types=1);

namespace App\Support\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns a spreadsheet into master data, one row at a time.
 *
 * Three decisions shape everything here:
 *
 *  1. **A bad row is skipped, not fatal.** Somebody exporting from an old system will have
 *     eleven rows wrong out of four hundred. Refusing the file teaches them to fix eleven
 *     rows blind; importing 389 and naming the eleven with their row numbers does not.
 *  2. **The natural key decides insert or update.** Re-uploading a corrected file must not
 *     duplicate the good rows, and the unique index counts soft-deleted rows too — so a code
 *     belonging to an archived record is restored rather than colliding.
 *  3. **The same rules as the form.** Validation comes from {@see ImportRegistry}, which
 *     carries the column widths and the business constraints. A spreadsheet is not a way past
 *     the checks a person typing the record would have faced.
 */
class Importer
{
    /** Enough to move a master list; small enough to finish inside one web request. */
    public const MAX_ROWS = 1000;

    /** More errors than this and the file is wrong, not the rows. */
    private const MAX_REPORTED_ERRORS = 50;

    /** @var array<string, int|null> table|value => id, so a repeated supplier is one query. */
    private array $lookups = [];

    /**
     * @param  array<string, mixed>  $definition
     * @return array{created: int, updated: int, skipped: int, rows: int, errors: list<array{row: int, messages: list<string>}>}
     */
    public function run(array $definition, string $path, string $extension): array
    {
        $rows = Spreadsheet::rows($path, $extension);
        $header = null;
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'rows' => 0, 'errors' => []];
        $line = 0;

        // One transaction for the file: a fatal error halfway through leaves no half-import
        // behind. Row-level failures are collected, not thrown, so they never reach here.
        DB::transaction(function () use ($definition, $rows, &$header, &$result, &$line): void {
            foreach ($rows as $values) {
                $line++;

                if ($header === null) {
                    $header = $this->mapHeader($definition, $values);

                    continue;
                }

                if ($this->isBlank($values)) {
                    continue;
                }

                $result['rows']++;

                if ($result['rows'] > self::MAX_ROWS) {
                    throw new ImportException('That file has more than '.self::MAX_ROWS.' rows. Split it and import the parts.');
                }

                $this->importRow($definition, $header, $values, $line, $result);
            }
        });

        if ($header === null) {
            throw new ImportException('That file is empty.');
        }

        return $result;
    }

    /**
     * Header cells to field keys.
     *
     * Both the key and its written label are accepted, and case, spaces and punctuation are
     * ignored — `Basic Salary`, `basic_salary` and `BASIC-SALARY` are one column. Columns
     * nobody asked for are dropped rather than refused: exports from other systems arrive
     * with an `id` and a `created at` on the end, and neither is a reason to reject the file.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<mixed>  $values
     * @return array<int, string> position => field key
     */
    private function mapHeader(array $definition, array $values): array
    {
        $known = [];

        foreach ($definition['fields'] as $key => $field) {
            $known[$this->normalise($key)] = $key;
            $known[$this->normalise(str_replace('_', ' ', $key))] = $key;
        }

        $header = [];

        foreach ($values as $position => $value) {
            $normalised = $this->normalise((string) $value);

            if (isset($known[$normalised])) {
                $header[$position] = $known[$normalised];
            }
        }

        $missing = [];

        foreach ($definition['fields'] as $key => $field) {
            if (($field['required'] ?? false) && ! in_array($key, $header, true)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new ImportException(
                'The file is missing required column'.(count($missing) === 1 ? '' : 's').': '.implode(', ', $missing).'.',
            );
        }

        return $header;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $header
     * @param  list<mixed>  $values
     * @param  array{created: int, updated: int, skipped: int, rows: int, errors: list<array{row: int, messages: list<string>}>}  $result
     */
    private function importRow(array $definition, array $header, array $values, int $line, array &$result): void
    {
        $raw = [];

        foreach ($header as $position => $key) {
            $raw[$key] = $values[$position] ?? null;
        }

        [$attributes, $errors] = $this->cast($definition, $raw);

        if ($errors === []) {
            $errors = $this->validate($definition, $attributes, $header);
        }

        if ($errors !== []) {
            $result['skipped']++;

            if (count($result['errors']) < self::MAX_REPORTED_ERRORS) {
                $result['errors'][] = ['row' => $line, 'messages' => $errors];
            }

            return;
        }

        $this->write($definition, $attributes, $result);
    }

    /**
     * Cell values to model attributes.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function cast(array $definition, array $raw): array
    {
        $attributes = [];
        $errors = [];

        foreach ($definition['fields'] as $key => $field) {
            $column = $field['column'] ?? $key;
            $present = array_key_exists($key, $raw);
            $value = $this->scalar($raw[$key] ?? null);

            if (! $present || $value === null) {
                // A column that was not in the file, or a cell left blank: the default
                // applies on insert and the stored value is left alone on update.
                if (array_key_exists('default', $field)) {
                    $attributes[$column] = ['__default' => $field['default']];
                }

                continue;
            }

            match ($field['type']) {
                'boolean' => $this->assign($attributes, $errors, $column, $key, $this->boolean($value)),
                'number' => $this->assign($attributes, $errors, $column, $key, $this->number($value)),
                'integer' => $this->assign($attributes, $errors, $column, $key, $this->integer($value)),
                'date' => $this->assign($attributes, $errors, $column, $key, $this->date($value)),
                'select' => $this->assign($attributes, $errors, $column, $key, $this->select($field, $value)),
                'lookup' => $this->assign($attributes, $errors, $column, $key, $this->lookup($field, $value)),
                default => $attributes[$column] = $value,
            };
        }

        return [$attributes, $errors];
    }

    /**
     * Either the cast value or the reason it could not be cast, which is a skipped row.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $errors
     */
    private function assign(array &$attributes, array &$errors, string $column, string $key, mixed $outcome): void
    {
        if (is_array($outcome) && isset($outcome['error'])) {
            $errors[] = "{$key}: {$outcome['error']}";

            return;
        }

        $attributes[$column] = $outcome;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $header
     * @return list<string>
     */
    private function validate(array $definition, array $attributes, array $header): array
    {
        $rules = [];
        $data = [];

        foreach ($definition['fields'] as $key => $field) {
            $column = $field['column'] ?? $key;

            if (! isset($field['rules'])) {
                continue;
            }

            // A column absent from the file is not a value the row is asserting, so it is
            // neither validated nor written — except where the field is required, which the
            // header check has already refused.
            if (! array_key_exists($column, $attributes)) {
                if (in_array($key, $header, true)) {
                    $rules[$key] = $field['rules'];
                    $data[$key] = null;
                }

                continue;
            }

            $value = $attributes[$column];
            $rules[$key] = $field['rules'];
            $data[$key] = is_array($value) ? $value['__default'] : $value;
        }

        $validator = Validator::make($data, $rules);

        return $validator->fails() ? array_values($validator->errors()->all()) : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attributes
     * @param  array{created: int, updated: int, skipped: int, rows: int, errors: list<array{row: int, messages: list<string>}>}  $result
     */
    private function write(array $definition, array $attributes, array &$result): void
    {
        /** @var class-string<Model> $class */
        $class = $definition['model'];
        $key = $definition['key'];

        // Archived records are searched too: the unique index does not forget one, so a code
        // that belongs to an archived record is an update-and-restore rather than a
        // duplicate-key failure. Dropping the scope by name rather than calling
        // `withTrashed()` keeps the model type honest — every importable model soft-deletes,
        // but the registry is typed as Model.
        $model = $class::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where($key, $attributes[$key])
            ->first();
        $existing = $model !== null;

        $model ??= new $class;

        foreach ($attributes as $column => $value) {
            if (is_array($value) && array_key_exists('__default', $value)) {
                // Defaults are for new records. An update leaves untouched columns alone,
                // because a file with five columns in it is not a statement about the rest.
                if (! $existing) {
                    $model->{$column} = $value['__default'];
                }

                continue;
            }

            $model->{$column} = $value;
        }

        $model->save();

        // A code that came back in a file is a record somebody wants back: archiving it was
        // the last decision, and re-importing it is the next one.
        if ($existing && $model->getAttribute('deleted_at') !== null && method_exists($model, 'restore')) {
            $model->restore();
        }

        $result[$existing ? 'updated' : 'created']++;
    }

    /** @param list<mixed> $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->scalar($value) !== null) {
                return false;
            }
        }

        return true;
    }

    /** A cell as a trimmed string, or null when it holds nothing. */
    private function scalar(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /** @return bool|array{error: string} */
    private function boolean(string $value): bool|array
    {
        $normalised = strtolower($value);

        if (in_array($normalised, ['1', 'yes', 'y', 'true', 't', 'active', 'approved'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'no', 'n', 'false', 'f', 'inactive'], true)) {
            return false;
        }

        return ['error' => "'{$value}' is not yes or no."];
    }

    /** @return float|array{error: string} */
    private function number(string $value): float|array
    {
        // Thousands separators and a stray currency symbol are what a spreadsheet produces
        // when somebody formatted the column; the number underneath is still readable.
        $cleaned = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return ['error' => "'{$value}' is not a number."];
        }

        return (float) $cleaned;
    }

    /** @return int|array{error: string} */
    private function integer(string $value): int|array
    {
        $number = $this->number($value);

        if (is_array($number)) {
            return $number;
        }

        if (floor($number) !== $number) {
            return ['error' => "'{$value}' must be a whole number."];
        }

        return (int) $number;
    }

    /** @return string|array{error: string} */
    private function date(string $value): string|array
    {
        // Excel keeps dates as a day count from 1900-01-00; a CSV of the same sheet carries
        // that number rather than a date, and 45 000 is not a year anybody meant.
        if (is_numeric($value) && (float) $value > 59 && (float) $value < 100000) {
            return date('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return ['error' => "'{$value}' is not a date. Use YYYY-MM-DD."];
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @return string|array{error: string}
     */
    private function select(array $field, string $value): string|array
    {
        $normalised = str_replace([' ', '-'], '_', strtolower($value));

        foreach ($field['options'] as $option) {
            if (strtolower((string) $option) === $normalised) {
                return (string) $option;
            }
        }

        return ['error' => "'{$value}' is not one of: ".implode(', ', $field['options']).'.'];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return int|array{error: string}
     */
    private function lookup(array $field, string $value): int|array
    {
        $table = $field['lookup']['table'];
        $cacheKey = $table.'|'.strtolower($value);

        if (! array_key_exists($cacheKey, $this->lookups)) {
            $query = DB::table($table);

            $query->where(function ($sub) use ($field, $value): void {
                foreach ($field['lookup']['columns'] as $column) {
                    $sub->orWhereRaw('LOWER('.$column.') = ?', [strtolower($value)]);
                }
            });

            $id = $query->value('id');

            $this->lookups[$cacheKey] = $id === null ? null : (int) $id;
        }

        $id = $this->lookups[$cacheKey];

        if ($id === null) {
            return ['error' => 'no '.Str::singular(str_replace('_', ' ', $table))." matches '{$value}'."];
        }

        return $id;
    }

    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
