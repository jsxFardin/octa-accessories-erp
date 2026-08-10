<?php

declare(strict_types=1);

namespace App\Support\Import\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Import\Importer;
use App\Support\Import\ImportException;
use App\Support\Import\ImportRegistry;
use App\Support\Import\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Exception\OpenSpoutException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spreadsheet import for the master-data lists, from one place.
 *
 * The mirror of {@see \App\Support\Export\Http\Controllers\ExportController}, with the same
 * two properties: the permission is resolved per resource inside the controller rather than
 * on the route, because `customer.import` and `item.import` are different rights; and every
 * import is written to the audit log, with its counts, because "where did these four hundred
 * suppliers come from" is a question somebody eventually asks.
 *
 * Three endpoints, because an import is three separate moments: read what the file should
 * contain, get a file already shaped that way, and upload one.
 */
class ImportController extends Controller
{
    /** A spreadsheet, not a database restore. */
    private const MAX_KILOBYTES = 10240;

    public function __construct(private readonly Importer $importer, private readonly AuditLogger $audit) {}

    /**
     * What this list accepts — the guidelines panel and nothing else reads it.
     *
     * Served rather than written into the page for the same reason the export columns are:
     * documentation kept beside the parser stays true, documentation kept in a Vue file does
     * not survive the second change to the parser.
     */
    public function fields(Request $request, string $resource): JsonResponse
    {
        $definition = $this->definition($request, $resource);

        $fields = [];

        foreach ($definition['fields'] as $key => $field) {
            $fields[] = [
                'name' => $key,
                'type' => $field['type'],
                'required' => $field['required'] ?? false,
                'example' => (string) ($field['example'] ?? ''),
                'description' => $field['description'] ?? '',
                'options' => array_map('strval', $field['options'] ?? []),
            ];
        }

        return response()->json([
            'label' => $definition['label'],
            'key' => $definition['key'],
            'maxRows' => Importer::MAX_ROWS,
            'maxSize' => '10MB',
            'extensions' => Spreadsheet::EXTENSIONS,
            'fields' => $fields,
        ]);
    }

    /**
     * A sample file: the headers, and one row showing the shape of each value.
     *
     * People do not read a field table and then build a file; they download something that
     * already works and replace the row.
     */
    public function sample(Request $request, string $resource): StreamedResponse
    {
        $definition = $this->definition($request, $resource);

        return response()->streamDownload(function () use ($definition): void {
            $handle = fopen('php://output', 'wb');

            // Excel opens a UTF-8 CSV as Latin-1 without this, which mangles Bangla names.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_keys($definition['fields']));
            fputcsv($handle, array_map(
                fn (array $field): string => (string) ($field['example'] ?? ''),
                array_values($definition['fields']),
            ));

            fclose($handle);
        }, $resource.'-import-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Upload. Returns counts and the rows that were skipped, with their line numbers. */
    public function __invoke(Request $request, string $resource): JsonResponse
    {
        $definition = $this->definition($request, $resource);

        $request->validate([
            // `extensions` rather than `mimes`: a CSV written by Excel is sniffed as
            // text/plain on one machine and application/vnd.ms-excel on the next, and a rule
            // that rejects a valid file every second upload is a rule people work around.
            'file' => [
                'required', 'file', 'max:'.self::MAX_KILOBYTES,
                'extensions:'.implode(',', Spreadsheet::EXTENSIONS),
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $result = $this->importer->run($definition, $file->getRealPath(), $extension);
        } catch (ImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (OpenSpoutException) {
            return response()->json([
                'message' => 'That file could not be read as a spreadsheet. Save it as CSV or XLSX and try again.',
            ], 422);
        }

        $this->audit->recordTable('imports', 0, 'imported', null, [
            'resource' => $resource,
            'file' => $file->getClientOriginalName(),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json($result);
    }

    /** @return array<string, mixed> */
    private function definition(Request $request, string $resource): array
    {
        $definition = ImportRegistry::find($resource) ?? abort(404, 'Nothing importable by that name.');

        abort_unless($request->user()?->hasPermission($definition['permission']), 403);

        return $definition;
    }
}
