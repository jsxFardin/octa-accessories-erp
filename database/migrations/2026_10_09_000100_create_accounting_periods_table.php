<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A closed month takes no new entries.
 *
 * The chain-of-custody close locked one scheme's movements; nothing locked the month for
 * stock, invoices, bills, receipts or payments, so a figure an auditor had been shown could
 * change the next day. A row here is a closed month; a month with no row is open. Closing
 * and reopening are recorded on the row and in the audit log, because reopening a month is
 * the act an auditor asks about.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_periods')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE accounting_periods (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    period_year  SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    status       VARCHAR(10) NOT NULL DEFAULT 'closed',
    closed_at    DATETIME(3),
    closed_by    BIGINT UNSIGNED,
    reopened_at  DATETIME(3),
    reopened_by  BIGINT UNSIGNED,
    note         VARCHAR(255),
    UNIQUE KEY accounting_periods_uq (period_year, period_month),
    KEY accounting_periods_closer_idx (closed_by),
    KEY accounting_periods_reopener_idx (reopened_by),
    CONSTRAINT accounting_periods_closer_fk   FOREIGN KEY (closed_by)   REFERENCES users(id),
    CONSTRAINT accounting_periods_reopener_fk FOREIGN KEY (reopened_by) REFERENCES users(id),
    CONSTRAINT accounting_periods_status_chk CHECK (status IN ('open','closed')),
    CONSTRAINT accounting_periods_month_chk  CHECK (period_month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
