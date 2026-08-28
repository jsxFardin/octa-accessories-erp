<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Product\Models\ArtworkVersion;
use App\Support\Platform\WorkQueue;
use Illuminate\Http\Request;
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
    public function __construct(private readonly WorkQueue $queue) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            // What is stuck on this user, before what is happening in the factory: nobody's
            // first question of the day is "how are we doing overall".
            'queue' => $this->queue->for($request->user()),
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
    /**
     * F-12 — the status breakdown, scoped to the same cards the "not yet closed" tile counts.
     *
     * This counted *every* job card, with no status filter, and sat directly beneath a tile
     * counting only the ones still open. The two agreed at the time of the audit purely
     * because no card had yet been closed or cancelled — the first `closed` card would have
     * made the breakdown outnumber the tile above it with no explanation on screen.
     *
     * The tile's definition is not changed. `completed` is genuinely still open work: the
     * state machine has `completed → closed` behind the `job_card.close` permission, so a
     * completed card is one waiting for somebody to close it, not one that is done with.
     * What was wrong here was the breakdown's scope, not the tile's.
     *
     * @return list<array<string, mixed>>
     */
    private function jobCardsByStatus(): array
    {
        // `toBase()` because this is an aggregate, not a set of job cards: hydrating a model
        // whose only attributes are `status` and `count` is what made `$row->count` an
        // undefined property on JobCard. The `open()` scope still decides what is counted, so
        // the definition stays in one place.
        return JobCard::query()
            ->open()
            ->toBase()
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
