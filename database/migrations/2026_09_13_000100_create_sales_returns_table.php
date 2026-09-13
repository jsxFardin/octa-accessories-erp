<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer return of goods that were delivered successfully and invoiced.
 *
 * Not the same event as `delivery_challans.returned`, which the application already has and
 * keeps: that one is a consignment refused at the gate or turned back in transit — goods that
 * never completed delivery. `DispatchService::postReturn()` reverses the *whole* challan, is
 * reachable only from `issued`/`in_transit`, and is terminal, so it can say nothing about a
 * customer who took delivery in March and sent a third of the carton back in May.
 *
 * This document carries the case the other cannot: partial quantities, several returns against
 * one invoice over time, and — the reason it exists at all — a return against an invoice that
 * has already been **paid**. `sales_invoices.paid` is terminal and stays terminal: a return
 * never reopens it, never touches `received_amount`, and never rewrites a receipt allocation.
 * The money genuinely arrived. What follows the return is a credit note, which is a new
 * document of its own.
 *
 * Shaped after `stock_adjustments` — the project's other approve-then-post inventory document
 * — rather than invented: same status vocabulary less `pending_approval`, same `approved_by`
 * and `created_by`, same single `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_returns')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sales_returns (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    sales_invoice_id    BIGINT UNSIGNED NOT NULL,
    delivery_challan_id BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    warehouse_id        BIGINT UNSIGNED NOT NULL,
    returned_on         DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason              VARCHAR(500) NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by         BIGINT UNSIGNED,
    created_by          BIGINT UNSIGNED,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY sales_returns_number_uq (number),
    KEY sales_returns_invoice_idx (sales_invoice_id),
    KEY sales_returns_challan_idx (delivery_challan_id),
    KEY sales_returns_customer_idx (customer_id, returned_on),
    KEY sales_returns_warehouse_idx (warehouse_id),
    KEY sales_returns_approver_idx (approved_by),
    KEY sales_returns_creator_idx (created_by),
    CONSTRAINT sales_returns_invoice_fk   FOREIGN KEY (sales_invoice_id)    REFERENCES sales_invoices(id),
    CONSTRAINT sales_returns_challan_fk   FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id),
    CONSTRAINT sales_returns_customer_fk  FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT sales_returns_warehouse_fk FOREIGN KEY (warehouse_id)        REFERENCES warehouses(id),
    CONSTRAINT sales_returns_approver_fk  FOREIGN KEY (approved_by)         REFERENCES users(id),
    CONSTRAINT sales_returns_creator_fk   FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT sales_returns_status_chk CHECK (status IN ('draft','approved','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
