<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Global search behind the ⌘K palette.
 *
 * Ten sidebar sections mean that finding SO-2026-0412 costs three clicks and a filter. This
 * answers the question people actually ask — "where is this number?" — across the documents
 * they are allowed to see.
 *
 * Each source is a small indexed lookup on a code or number column, capped hard: the palette
 * is a jump list, not a report. Anything a user lacks permission for is not queried at all.
 */
class SearchController extends Controller
{
    private const PER_SOURCE = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $user = $request->user();

        $sources = [
            ['sales_order.view_any', 'Sales orders', 'sales_orders', '/sales-orders', ['number'], 'customer'],
            ['quotation.view_any', 'Quotations', 'quotations', '/quotations', ['number'], 'customer'],
            ['inquiry.view_any', 'Inquiries', 'inquiries', '/inquiries', ['number'], 'customer'],
            ['job_card.view_any', 'Job cards', 'job_cards', '/job-cards', ['number'], null],
            ['purchase_order.view_any', 'Purchase orders', 'purchase_orders', '/purchase-orders', ['number'], 'supplier'],
            ['grn.view_any', 'Goods receipts', 'grns', '/grns', ['number'], 'supplier'],
            ['stock_lot.view_any', 'Lots', 'stock_lots', '/lots', ['lot_no'], null],
            ['customer.view_any', 'Customers', 'customers', '/customers', ['code', 'name'], null],
            ['supplier.view_any', 'Suppliers', 'suppliers', '/suppliers', ['code', 'name'], null],
            ['item.view_any', 'Items', 'items', '/items', ['code', 'name'], null],
            ['product.view_any', 'Products', 'products', '/products', ['code', 'name'], null],
        ];

        $groups = [];

        foreach ($sources as [$permission, $label, $table, $path, $columns, $partyColumn]) {
            if ($user === null || ! $user->hasPermission($permission)) {
                continue;
            }

            $rows = $this->lookup($table, $columns, $like, $partyColumn);

            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'label' => $label,
                'items' => array_map(
                    fn (array $row): array => [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'subtitle' => $row['subtitle'],
                        'href' => $path.'/'.$row['id'],
                    ],
                    $rows,
                ),
            ];
        }

        return response()->json(['groups' => $groups]);
    }

    /**
     * @param  list<string>  $columns
     * @return list<array{id: int, title: string, subtitle: ?string}>
     */
    private function lookup(string $table, array $columns, string $like, ?string $partyColumn): array
    {
        $query = DB::table($table)->where(function ($q) use ($columns, $like): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $like);
            }
        });

        // The party name is what makes a bare document number recognisable in a list.
        if ($partyColumn !== null) {
            $partyTable = $partyColumn.'s';
            $query->leftJoin($partyTable, $partyTable.'.id', '=', $table.'.'.$partyColumn.'_id')
                ->select([$table.'.id', ...array_map(fn (string $c): string => $table.'.'.$c, $columns), $partyTable.'.name as party'])
                // A deleted customer must not resurrect a document into the palette's subtitle.
                ->addSelect([$table.'.status']);
        } else {
            $query->select([$table.'.id', ...$columns]);
        }

        $rows = $query->orderByDesc($table.'.id')->limit(self::PER_SOURCE)->get();

        return $rows->map(function (object $row) use ($columns): array {
            // A query-builder row carries whatever the select asked for, so it is read as the
            // bag of columns it actually is rather than a shape that can be annotated.
            $values = (array) $row;
            $primary = $columns[0];
            $secondary = $columns[1] ?? null;

            $subtitle = $secondary !== null && isset($values[$secondary])
                ? (string) $values[$secondary]
                : trim(implode(' · ', array_filter([
                    $values['party'] ?? null,
                    isset($values['status']) ? str_replace('_', ' ', (string) $values['status']) : null,
                ])));

            return [
                'id' => (int) $values['id'],
                'title' => (string) ($values[$primary] ?? '(unnumbered)'),
                'subtitle' => $subtitle === '' ? null : $subtitle,
            ];
        })->all();
    }
}
