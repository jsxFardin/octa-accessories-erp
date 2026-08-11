<?php

declare(strict_types=1);

namespace App\Support\Reference\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One controller for every lookup table (see ReferenceRegistry).
 *
 * The definition supplies the columns, the validation and the permission; this only decides
 * how a row is read, written and refused. Twenty tables therefore cost twenty definitions
 * rather than twenty controllers that would drift apart within a month.
 */
class ReferenceController extends Controller
{
    /** How many rows a hub card shows before it defers to the full screen. */
    private const CARD_ROWS = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The Setup hub: one tab per group, each tab a set of cards showing the list inline.
     *
     * Only the open tab's rows are loaded. Twenty-five lists with their rows and their
     * reference options in one payload would be a slow screen that is 90% unread.
     */
    public function hub(Request $request): Response
    {
        $tabs = [];
        $visible = [];

        foreach (ReferenceRegistry::all() as $slug => $definition) {
            if (! $this->allows($request, $slug, 'view_any')) {
                continue;
            }

            $visible[$definition['group']][$slug] = $definition;
        }

        foreach (ReferenceRegistry::GROUPS as $key => $label) {
            if (isset($visible[$key])) {
                $tabs[] = ['key' => $key, 'label' => $label, 'count' => count($visible[$key])];
            }
        }

        // Every list is editable now — product type and cut type included, since the rules
        // behind them are columns rather than `match` arms. A user who may read no list at
        // all gets no tabs, and the hub says so rather than opening on a tab that is not there.
        $current = (string) $request->query('tab', $tabs[0]['key'] ?? '');

        if (! isset($visible[$current])) {
            $current = $tabs[0]['key'] ?? '';
        }

        $cards = [];

        foreach ($visible[$current] ?? [] as $slug => $definition) {
            $cards[] = [
                'slug' => $slug,
                'label' => $definition['label'],
                'singular' => $definition['singular'],
                'icon' => $definition['icon'],
                'description' => $definition['description'],
                'fields' => $definition['fields'],
                'options' => $this->referenceOptions($definition),
                'total' => DB::table($definition['table'])->count(),
                // Capped: a card is a working list, not a report. The full screen paginates.
                'rows' => DB::table($definition['table'])
                    ->orderBy($this->hubSortColumn($definition))
                    ->limit(self::CARD_ROWS)
                    ->get()
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
                'display' => $this->displayFields($definition),
                'can' => [
                    'create' => $this->allows($request, $slug, 'create'),
                    'update' => $this->allows($request, $slug, 'update'),
                    'delete' => $this->allows($request, $slug, 'delete'),
                ],
            ];
        }

        return Inertia::render('Setup/Index', [
            'tabs' => $tabs,
            'current' => $current,
            'cards' => $cards,
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function hubSortColumn(array $definition): string
    {
        $sort = ltrim((string) ($definition['defaultSort'] ?? ''), '-');
        $columns = array_column($definition['fields'], 'name');

        return in_array($sort, $columns, true) ? $sort : ($columns[0] ?? 'id');
    }

    /**
     * What a card shows per row: a title, a subtitle and up to two badges. The full field set
     * belongs in the form, not in a list someone is scanning.
     *
     * @param  array<string, mixed>  $definition
     * @return array{title: string, subtitle: ?string, badges: list<string>}
     */
    private function displayFields(array $definition): array
    {
        $names = array_column($definition['fields'], 'name');
        $byName = array_combine($names, $definition['fields']);

        $title = in_array('code', $names, true) ? 'code' : ($names[0] ?? 'id');
        $subtitle = in_array('name', $names, true) && $title !== 'name' ? 'name' : null;

        $badges = array_values(array_filter(
            $names,
            fn (string $name): bool => $name !== $title
                && $name !== $subtitle
                && in_array($byName[$name]['type'], ['select', 'boolean'], true),
        ));

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'badges' => array_slice($badges, 0, 2),
        ];
    }

    public function index(Request $request, string $reference): Response
    {
        $definition = $this->definition($reference);
        $this->authorise($request, $reference, 'view_any');

        $query = DB::table($definition['table']);
        $term = trim((string) $request->string('q'));

        if ($term !== '' && ($definition['searchable'] ?? []) !== []) {
            $query->where(function ($sub) use ($definition, $term): void {
                foreach ($definition['searchable'] as $column) {
                    $sub->orWhere($column, 'like', "%{$term}%");
                }
            });
        }

        $sort = (string) ($request->query('sort') ?: ($definition['defaultSort'] ?? 'id'));
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $columns = array_column($definition['fields'], 'name');

        $query->orderBy(in_array($column, [...$columns, 'id'], true) ? $column : 'id', $direction);

        return Inertia::render('Setup/Reference', [
            'reference' => [
                'slug' => $reference,
                'label' => $definition['label'],
                'singular' => $definition['singular'],
                'icon' => $definition['icon'],
                'description' => $definition['description'],
                'fields' => $definition['fields'],
                'searchable' => ($definition['searchable'] ?? []) !== [],
            ],
            'rows' => $query->paginate(50)->withQueryString(),
            'filters' => $request->only(['q', 'sort']),
            // Every `reference` field needs its options; resolved here so the form never has
            // to fetch, and a 12-row table renders as fast as a 1200-row one.
            'options' => $this->referenceOptions($definition),
            'can' => [
                'create' => $this->allows($request, $reference, 'create'),
                'update' => $this->allows($request, $reference, 'update'),
                'delete' => $this->allows($request, $reference, 'delete'),
            ],
        ]);
    }

    public function store(Request $request, string $reference): RedirectResponse
    {
        $definition = $this->definition($reference);
        $this->authorise($request, $reference, 'create');

        $data = $this->validated($request, $definition, null);

        try {
            $id = DB::table($definition['table'])->insertGetId($data);
        } catch (QueryException $e) {
            return back()->with('error', $this->explain($e, $definition));
        }

        $this->audit->recordTable($definition['table'], (int) $id, 'created', null, $data);

        return back()->with('success', ucfirst($definition['singular']).' created.');
    }

    public function update(Request $request, string $reference, int $id): RedirectResponse
    {
        $definition = $this->definition($reference);
        $this->authorise($request, $reference, 'update');

        $existing = DB::table($definition['table'])->where('id', $id)->first();

        if ($existing === null) {
            abort(404);
        }

        $data = $this->validated($request, $definition, $id);

        try {
            DB::table($definition['table'])->where('id', $id)->update($data);
        } catch (QueryException $e) {
            return back()->with('error', $this->explain($e, $definition));
        }

        $this->audit->recordTable($definition['table'], $id, 'updated', (array) $existing, $data);

        return back()->with('success', ucfirst($definition['singular']).' updated.');
    }

    /**
     * Lookups are deleted, not archived — but only while nothing points at them. The database
     * knows which rows are referenced far better than a hand-written list of checks would, so
     * the foreign key is left to say no and its refusal is translated.
     */
    public function destroy(Request $request, string $reference, int $id): RedirectResponse
    {
        $definition = $this->definition($reference);
        $this->authorise($request, $reference, 'delete');

        $existing = DB::table($definition['table'])->where('id', $id)->first();

        if ($existing === null) {
            abort(404);
        }

        try {
            DB::table($definition['table'])->where('id', $id)->delete();
        } catch (QueryException $e) {
            if ($this->isForeignKeyViolation($e)) {
                return back()->with(
                    'error',
                    'This '.$definition['singular'].' is in use and cannot be deleted. '
                    .'Records already refer to it; deactivate it instead if that option exists.',
                );
            }

            throw $e;
        }

        $this->audit->recordTable($definition['table'], $id, 'deleted', (array) $existing);

        return back()->with('success', ucfirst($definition['singular']).' deleted.');
    }

    /** @return array<string, mixed> */
    private function definition(string $reference): array
    {
        return ReferenceRegistry::find($reference) ?? abort(404, 'Unknown reference list.');
    }

    private function allows(Request $request, string $reference, string $action): bool
    {
        return (bool) $request->user()?->hasPermission(
            ReferenceRegistry::permissionResource($reference).'.'.$action,
        );
    }

    private function authorise(Request $request, string $reference, string $action): void
    {
        abort_unless($this->allows($request, $reference, $action), 403);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $definition, ?int $ignoreId): array
    {
        $rules = [];

        foreach ($definition['fields'] as $field) {
            $fieldRules = $field['rules'] ?? [];

            if ($field['type'] === 'boolean') {
                $fieldRules = ['boolean'];
            }

            if ($field['type'] === 'select') {
                $fieldRules = [...$fieldRules, Rule::in($field['options'])];

                if (! in_array('nullable', $fieldRules, true)) {
                    $fieldRules[] = 'required';
                }
            }

            // A single-column unique index is checked here so the user gets a field-level
            // error rather than the database's version of one.
            if (($field['unique'] ?? false) === true) {
                $fieldRules[] = Rule::unique($definition['table'], $field['name'])->ignore($ignoreId);
            }

            $rules[$field['name']] = $fieldRules;
        }

        $validated = $request->validate($rules);
        $row = [];

        foreach ($definition['fields'] as $field) {
            $value = $validated[$field['name']] ?? $field['default'] ?? null;

            $row[$field['name']] = match ($field['type']) {
                'boolean' => (bool) $value,
                'number' => $value === null || $value === '' ? null : (int) $value,
                'decimal' => $value === null || $value === '' ? null : (float) $value,
                default => $value === '' ? null : $value,
            };
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, list<array{value: int, label: string}>>
     */
    private function referenceOptions(array $definition): array
    {
        $options = [];

        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'reference') {
                continue;
            }

            $table = $field['reference'];
            $label = $this->labelColumn($table, $field['referenceLabel'] ?? null);
            $hint = $label === 'code' && Schema::hasColumn($table, 'name') ? 'name' : null;

            $options[$field['name']] = DB::table($table)
                ->orderBy($label)
                ->limit(500)
                ->get(array_filter(['id', $label, $hint]))
                ->map(fn (object $row): array => [
                    'value' => (int) $row->id,
                    'label' => (string) $row->{$label},
                    'hint' => $hint === null ? null : $row->{$hint},
                ])
                ->all();
        }

        return $options;
    }

    /**
     * What to show a human for a referenced row.
     *
     * Asking every table for a `name` column is how this screen 500s: `certifications` has a
     * certificate number and no name at all. The first column that actually exists wins.
     */
    private function labelColumn(string $table, ?string $preferred): string
    {
        foreach (array_filter([$preferred, 'name', 'code', 'certificate_no', 'label', 'title']) as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return 'id';
    }

    /** @param array<string, mixed> $definition */
    private function explain(QueryException $e, array $definition): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Duplicate entry')) {
            $combination = isset($definition['uniqueWith'])
                ? implode(' + ', $definition['uniqueWith'])
                : 'code';

            return "A {$definition['singular']} with the same {$combination} already exists.";
        }

        // A CHECK constraint means the value is outside the vocabulary the schema allows —
        // worth naming the column rather than showing raw SQL.
        if (str_contains($message, 'CONSTRAINT') && str_contains($message, 'CHECK')) {
            preg_match('/`?(\w+)_chk`?/', $message, $matches);

            return 'The database refused that value'
                .(isset($matches[1]) ? " for {$matches[1]}" : '').'.';
        }

        if ($this->isForeignKeyViolation($e)) {
            return 'That referenced record does not exist.';
        }

        throw $e;
    }

    private function isForeignKeyViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23503'], true)
            && str_contains($e->getMessage(), 'foreign key');
    }
}
