<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BR-34. Read-only by design: `next_number` is allocated under a row lock inside the
 * transaction that inserts the document, and editing it by hand is how a VAT-relevant series
 * acquires a duplicate.
 */
class NumberSequenceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/NumberSequences', [
            'sequences' => DB::table('number_sequences')
                ->orderBy('document_type')->orderBy('series_key')
                ->get()
                ->map(fn ($row): array => [
                    ...(array) $row,
                    'next_formatted' => $row->prefix === 'L'
                        ? sprintf('L%s-%s', $row->series_key, str_pad((string) $row->next_number, (int) $row->padding, '0', STR_PAD_LEFT))
                        : sprintf('%s-%s-%s', $row->prefix, $row->series_key, str_pad((string) $row->next_number, (int) $row->padding, '0', STR_PAD_LEFT)),
                ]),
        ]);
    }
}
