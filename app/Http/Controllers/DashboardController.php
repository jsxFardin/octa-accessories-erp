<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Product\Models\ArtworkVersion;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The executive dashboard (Module 14).
 *
 * Deliberately reads the derived views rather than recomputing: `v_order_book` and
 * `v_machine_load` exist so that the sales dashboard and the planning board agree with each
 * other and with the reports (02-database-schema §4).
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'tiles' => $this->tiles(),
            'orderBook' => $this->orderBook(),
            'jobCardsByStatus' => $this->jobCardsByStatus(),
            'artworkQueue' => $this->artworkQueue(),
            'expiringCertificates' => $this->expiringCertificates(),
            'machineLoad' => $this->machineLoad(),
        ]);
    }

    /** @return array<string, mixed> */
    private function tiles(): array
    {
        $openOrders = DB::table('sales_orders')
            ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
            ->count();

        $lateOrders = DB::table('sales_orders')
            ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
            ->whereDate('delivery_date', '<', now())
            ->count();

        return [
            'open_orders' => $openOrders,
            'late_orders' => $lateOrders,
            'open_job_cards' => JobCard::query()->open()->count(),
            'on_floor' => JobCard::query()->onFloor()->count(),
            // Gate 1 as a number: how much work is waiting on a customer signature.
            'artwork_pending' => ArtworkVersion::query()->where('status', ArtworkVersion::SUBMITTED)->count(),
            'material_pending' => JobCard::query()->where('status', JobCard::MATERIAL_PENDING)->count(),
            'quotations_open' => DB::table('quotations')->where('status', 'sent')->count(),
            'stock_value' => (float) DB::table('stock_balances as sb')
                ->join('stock_lots as sl', 'sl.id', '=', 'sb.lot_id')
                ->sum(DB::raw('sb.balance_qty * sl.unit_cost')),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function orderBook(): array
    {
        return DB::table('v_order_book')
            ->orderBy('promised_date')
            ->limit(12)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /** @return list<array{status: string, count: int}> */
    private function jobCardsByStatus(): array
    {
        return DB::table('job_cards')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row): array => ['status' => $row->status, 'count' => (int) $row->count])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function artworkQueue(): array
    {
        return ArtworkVersion::query()
            ->where('status', ArtworkVersion::SUBMITTED)
            ->with('artwork.product.customer')
            ->orderBy('submitted_at')
            ->limit(8)
            ->get()
            ->map(fn (ArtworkVersion $version): array => [
                'id' => $version->id,
                'artwork_id' => $version->artwork_id,
                'code' => $version->artwork?->code,
                'title' => $version->artwork?->title,
                'version_no' => $version->version_no,
                'customer' => $version->artwork?->product?->customer?->name,
                'submitted_at' => $version->submitted_at,
                'waiting_days' => $version->submitted_at?->diffInDays(now()) ?? 0,
            ])
            ->all();
    }

    /**
     * BR-43 — a certificate that expires mid-shipment blocks the claim. Sixty days' warning is
     * the default because re-certification takes that long.
     *
     * @return list<array<string, mixed>>
     */
    private function expiringCertificates(): array
    {
        return DB::table('certifications')
            ->where('status', 'active')
            ->whereRaw('expires_on <= DATE_ADD(CURDATE(), INTERVAL reminder_days DAY)')
            ->orderBy('expires_on')
            ->get(['id', 'scheme', 'certificate_no', 'expires_on'])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * BR-27 — the numerator of utilisation, straight from the view.
     *
     * @return list<array<string, mixed>>
     */
    private function machineLoad(): array
    {
        return DB::table('v_machine_load')
            ->whereBetween('load_date', [now()->toDateString(), now()->addDays(6)->toDateString()])
            ->orderBy('load_date')
            ->limit(40)
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();
    }
}
