<?php

declare(strict_types=1);

namespace App\Support\Platform;

use App\Models\User;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * What is waiting on *this* user.
 *
 * The dashboard answers "how is the factory doing"; nobody's first question in the morning is
 * that. It is "what is stuck on me" — and until now the only way to find out was to open six
 * lists and read their status columns.
 *
 * Every entry is gated by the permission that would let the user act on it, so a queue never
 * offers work its owner cannot do. The counts are cheap indexed aggregates: this runs on every
 * dashboard load.
 */
class WorkQueue
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return list<array{
     *     key: string, label: string, count: int, href: string, tone: string, hint: string
     * }>
     */
    public function for(User $user): array
    {
        $entries = [];

        // --- Approvals ----------------------------------------------------------------
        if ($user->hasPermission('purchase_order.approve')) {
            $band = $this->settings->decimal('po_approval_band_manager', 100000);
            $isMd = $user->hasRole('md') || $user->hasRole('super_admin');

            $query = DB::table('purchase_orders')->where('status', 'pending_approval');

            // A purchase manager is not shown orders only the MD can sign — that is someone
            // else's queue, and a count you cannot clear is noise (06-rbac §5).
            if (! $isMd) {
                $query->where('total', '<=', $band);
            }

            $entries[] = [
                'key' => 'po_approval',
                'label' => 'Purchase orders to approve',
                'count' => $query->count(),
                'href' => '/purchase-orders?status=pending_approval',
                'tone' => 'warning',
                'hint' => $isMd
                    ? 'Every band, including those above the manager limit.'
                    : 'Within your approval band of '.number_format($band, 0).'.',
            ];
        }

        if ($user->hasPermission('purchase_requisition.approve')) {
            $entries[] = [
                'key' => 'pr_approval',
                'label' => 'Requisitions to approve',
                'count' => DB::table('purchase_requisitions')->where('status', 'submitted')->count(),
                'href' => '/purchase-requisitions?status=submitted',
                'tone' => 'warning',
                'hint' => 'Raised by the factory, not yet agreed to buy.',
            ];
        }

        if ($user->hasPermission('artwork.approve')) {
            $entries[] = [
                'key' => 'artwork_approval',
                'label' => 'Artwork awaiting sign-off',
                'count' => DB::table('artwork_versions')->where('status', 'submitted')->count(),
                'href' => '/artworks?state=awaiting_approval',
                'tone' => 'warning',
                'hint' => 'Gate 1 — no job card can be released until one of these is approved.',
            ];
        }

        if ($user->hasPermission('sales_order.release_credit_hold')) {
            $entries[] = [
                'key' => 'credit_hold',
                'label' => 'Orders on credit hold',
                'count' => DB::table('sales_orders')->where('status', 'credit_hold')->count(),
                'href' => '/sales-orders?status=credit_hold',
                'tone' => 'danger',
                'hint' => 'Confirmed nowhere until the hold is released (BR-46).',
            ];
        }

        // --- Work that has stalled ------------------------------------------------------
        if ($user->hasPermission('job_card.view_any')) {
            $entries[] = [
                'key' => 'material_pending',
                'label' => 'Job cards waiting on material',
                'count' => DB::table('job_cards')->where('status', 'material_pending')->count(),
                'href' => '/job-cards?status=material_pending',
                'tone' => 'danger',
                'hint' => 'Released but unable to start — the floor is idle on these.',
            ];
        }

        if ($user->hasPermission('qc_inspection.view_any')) {
            // Not "rejections without a disposition": `qc_inspections_rejected_chk` makes that
            // state impossible, so the count would be a permanent zero. What is genuinely
            // outstanding is a concession with no customer evidence recorded against it — the
            // first thing a brand disputes at audit (BR-33).
            $entries[] = [
                'key' => 'concession_evidence',
                'label' => 'Concessions without customer evidence',
                'count' => DB::table('qc_inspections')
                    ->where('disposition', 'concession')
                    ->where(fn ($query) => $query->whereNull('disposition_ref')->orWhere('disposition_ref', ''))
                    ->count(),
                'href' => '/qc-inspections',
                'tone' => 'danger',
                'hint' => 'A concession the customer cannot be shown to have agreed to is the first thing disputed at audit.',
            ];

            $entries[] = [
                'key' => 'rework_open',
                'label' => 'Lots sent for rework',
                'count' => DB::table('qc_inspections')->where('disposition', 'rework')->count(),
                'href' => '/qc-inspections',
                'tone' => 'warning',
                'hint' => 'Back on an operation rather than moving forward.',
            ];
        }

        if ($user->hasPermission('quotation.view_any')) {
            $entries[] = [
                'key' => 'quotations_expiring',
                'label' => 'Quotations expiring this week',
                'count' => DB::table('quotations')
                    ->where('status', 'sent')
                    ->whereNotNull('valid_until')
                    ->whereBetween('valid_until', [now()->toDateString(), now()->addWeek()->toDateString()])
                    ->count(),
                'href' => '/quotations?status=sent',
                'tone' => 'warning',
                'hint' => 'Chase or revise before the price lapses.',
            ];
        }

        if ($user->hasPermission('stock_lot.view_any')) {
            $days = $this->settings->int('expiry_alert_days', 30);

            $entries[] = [
                'key' => 'expiring_stock',
                'label' => 'Lots expiring soon',
                'count' => DB::table('stock_lots')
                    ->where('status', 'available')
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
                    ->count(),
                'href' => '/lots',
                'tone' => 'warning',
                'hint' => "Ink and chemicals within {$days} days of expiry (BR-39).",
            ];
        }

        // An entry with nothing in it is not reassurance, it is a row to scan past.
        return array_values(array_filter($entries, fn (array $entry): bool => $entry['count'] > 0));
    }
}
