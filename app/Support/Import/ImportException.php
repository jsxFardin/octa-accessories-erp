<?php

declare(strict_types=1);

namespace App\Support\Import;

use RuntimeException;

/**
 * The file itself is wrong — no header, no required column, too many rows.
 *
 * Distinct from a bad row, which is collected and reported alongside the rows that worked.
 * This is thrown rather than `abort()`ed because the import endpoints are XHR: `abort(422)`
 * renders the HTML error page for anything outside `api/*` (bootstrap/app.php), and an upload
 * panel cannot show a web page to somebody who mis-named a column.
 */
class ImportException extends RuntimeException {}
