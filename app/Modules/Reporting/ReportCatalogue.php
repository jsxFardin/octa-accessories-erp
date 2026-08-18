<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Modules\Reporting\Queries\DispatchReport;
use App\Modules\Reporting\Queries\FulfilmentReport;
use App\Modules\Reporting\Queries\NcrCapaReport;
use App\Modules\Reporting\Queries\PayablesReport;
use App\Modules\Reporting\Queries\ProductionReport;
use App\Modules\Reporting\Queries\PurchaseReport;
use App\Modules\Reporting\Queries\ReceivableReport;
use App\Modules\Reporting\Queries\ReportQuery;
use App\Modules\Reporting\Queries\StockReport;

/**
 * The six operational reports. Each class is the query; this catalogue is the menu.
 *
 * Permission is `report.view` for every report — the catalogue already has a single Reporting
 * resource. Finer splits can wait until a role needs to see production and not receivables.
 */
final class ReportCatalogue
{
    /**
     * @return array<string, class-string<ReportQuery>>
     */
    public static function map(): array
    {
        return [
            'fulfilment' => FulfilmentReport::class,
            'production' => ProductionReport::class,
            'stock' => StockReport::class,
            'dispatch' => DispatchReport::class,
            'receivables' => ReceivableReport::class,
            'payables' => PayablesReport::class,
            'purchases' => PurchaseReport::class,
            'ncr-capa' => NcrCapaReport::class,
        ];
    }

    public static function make(string $key): ReportQuery
    {
        $class = self::map()[$key] ?? null;

        if ($class === null) {
            abort(404, 'Unknown report.');
        }

        return app($class);
    }

    /**
     * @return list<array{key: string, title: string, subtitle: string}>
     */
    public static function menu(): array
    {
        return array_values(array_map(
            fn (string $class): array => [
                'key' => app($class)->key(),
                'title' => app($class)->title(),
                'subtitle' => app($class)->subtitle(),
            ],
            self::map(),
        ));
    }
}
