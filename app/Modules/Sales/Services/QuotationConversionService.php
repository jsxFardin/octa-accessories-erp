<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\MasterData\Models\CustomerAddress;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Audit\AuditLogger;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Settings\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Q3 / Q5 — the single writer for quotation → sales order.
 *
 * The rule the controller used to carry was Q3 alone ("only an accepted quotation converts"),
 * and `accepted` is a terminal status: nothing about converting moved the quotation out of
 * it. So the same accepted quotation converted as many times as it was asked to, and a second
 * POST to `/quotations/{id}/convert` minted a second draft order against an order that had
 * already been produced and delivered.
 *
 * Q5 closes it: the conversion is refused while a sales order raised from this quotation is
 * still standing. The schema's one-to-many (01-domain-model §1, `QUOTATION ||--o{ SALES_ORDER`)
 * is left intact deliberately — a cancelled order must be re-raisable without revising the
 * quotation, and that is the only case the cardinality was ever for. Every other case is a
 * duplicate.
 *
 * Everything happens under the quotation's row lock, so two simultaneous requests serialise
 * rather than both passing the check.
 */
class QuotationConversionService
{
    /** An order in one of these statuses no longer occupies the quotation (Q5). */
    private const SPENT_ORDER_STATUSES = ['cancelled'];

    public function __construct(
        private readonly CostSheetCalculator $calculator,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The order(s) this quotation has already become that still stand.
     *
     * @return Collection<int, SalesOrder>
     */
    public function liveOrders(Quotation $quotation): Collection
    {
        return SalesOrder::query()
            ->where('quotation_id', $quotation->getKey())
            ->whereNotIn('status', self::SPENT_ORDER_STATUSES)
            ->orderByDesc('id')
            ->get();
    }

    /** Whether the convert action should be offered at all — the same answer the server enforces. */
    public function isConvertible(Quotation $quotation): bool
    {
        return $this->refusalReason($quotation) === null;
    }

    /**
     * Why this quotation cannot be converted, in words a merchandiser can act on — or null
     * when it can. Read by both the guard and the screen, so the button and the server can
     * never disagree.
     */
    public function refusalReason(Quotation $quotation): ?string
    {
        if ($quotation->status !== 'accepted') {
            return sprintf(
                '%s is %s, not accepted. Only an accepted quotation converts to a sales order (Q3). Record the customer%s acceptance on the quotation first.',
                $quotation->reference(),
                str_replace('_', ' ', $quotation->status),
                "'s",
            );
        }

        $existing = $this->liveOrders($quotation);

        if ($existing->isNotEmpty()) {
            $names = $existing->map(
                fn (SalesOrder $order): string => ($order->number ?? "draft order #{$order->id}")
                    .' ('.str_replace('_', ' ', $order->status).')',
            )->join(', ');

            return sprintf(
                'This quotation has already been converted to %s. A second sales order cannot be created from it (Q5). Open that order to amend it, or cancel it first if the order genuinely has to be re-raised.',
                $names,
            );
        }

        if ($quotation->lines()->count() === 0) {
            return 'This quotation has no lines, so there is nothing to order. Add a line, or convert a different quotation.';
        }

        return null;
    }

    /**
     * Convert, or refuse with the reason.
     *
     * @param  array{customer_po_no?: string|null, delivery_date?: string|null}  $data
     *
     * @throws ValidationException when a business rule refuses the conversion — nothing is
     *                             written, so a refused attempt leaves no partial order
     */
    public function convert(Quotation $quotation, array $data, User $user): SalesOrder
    {
        if (! $user->hasPermission('sales_order.create')) {
            throw ValidationException::withMessages([
                'quotation' => 'You do not have the [sales_order.create] permission, so this quotation cannot be converted. Ask a merchandiser or sales manager to raise the order.',
            ]);
        }

        return DB::transaction(function () use ($quotation, $data, $user): SalesOrder {
            // The lock is what makes the check below true rather than merely recently true:
            // two clicks a millisecond apart serialise here and the second one is refused.
            /** @var Quotation $locked */
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());

            $refusal = $this->refusalReason($locked);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['quotation' => $refusal]);
            }

            $deliveryAddress = CustomerAddress::defaultDeliveryFor((int) $locked->customer_id);
            $billingAddress = CustomerAddress::query()
                ->where('customer_id', $locked->customer_id)
                ->whereIn('kind', ['billing', 'both'])
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            $order = SalesOrder::query()->create([
                'quotation_id' => $locked->getKey(),
                'customer_id' => $locked->customer_id,
                'customer_po_no' => $data['customer_po_no'] ?? null,
                'order_date' => now()->toDateString(),
                'delivery_date' => $data['delivery_date'] ?? null,
                'currency_id' => $locked->currency_id,
                'exchange_rate' => $locked->exchange_rate,
                'payment_term_id' => $locked->payment_term_id,
                // D4 — a quotation is a price, not a shipment, so it names no address. An
                // order that inherits that emptiness passes it to its packing list and then
                // to its challan, and the goods reach the gate with nowhere to go. The
                // customer's default delivery address is where an order starts; the
                // merchandiser may still change it before confirmation.
                'delivery_address_id' => $deliveryAddress?->id,
                'billing_address_id' => $billingAddress?->id,
                'merchandiser_id' => $user->id,
                'priority' => 'normal',
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            $subtotal = 0.0;

            foreach ($locked->lines as $index => $line) {
                $lineTotal = $this->calculator->lineValue((int) $line->qty, (float) $line->rate_per_m)
                    + (float) $line->tooling_charge;
                $subtotal += $lineTotal;

                SalesOrderLine::query()->create([
                    'sales_order_id' => $order->id,
                    'line_no' => $index + 1,
                    'product_id' => $line->product_id,
                    'product_spec_id' => $line->product_spec_id
                        ?? Product::query()->find($line->product_id)?->currentSpec?->id,
                    'description' => $line->description,
                    'ordered_qty' => $line->qty,
                    'rate_per_m' => $line->rate_per_m,
                    'tooling_charge' => $line->tooling_charge,
                    'line_total' => $lineTotal,
                    'over_tolerance_pct' => $this->settings->decimal('over_tolerance_pct', 5),
                    'under_tolerance_pct' => $this->settings->decimal('under_tolerance_pct', 5),
                    'status' => 'open',
                ]);
            }

            $order->forceFill(['subtotal' => $subtotal, 'total' => $subtotal])->save();

            // The quotation's own row in the trail: what it became, and when. The order's
            // `created` row is written by Auditable in the same transaction.
            $this->audit->recordConversion($locked, $order, [
                'quotation' => $locked->reference(),
                'customer_po_no' => $data['customer_po_no'] ?? null,
                'line_count' => $locked->lines->count(),
                'total' => round($subtotal, 4),
            ]);

            return $order;
        });
    }
}
