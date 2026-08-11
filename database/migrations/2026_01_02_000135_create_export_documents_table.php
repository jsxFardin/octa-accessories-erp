<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('export_documents')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE export_documents (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_challan_id BIGINT UNSIGNED,
    sales_order_id      BIGINT UNSIGNED,
    doc_type            VARCHAR(30) NOT NULL,
    doc_no              VARCHAR(80) NOT NULL,
    doc_date            DATE,
    file_path           VARCHAR(500),
    remarks             VARCHAR(255),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY export_documents_challan_idx (delivery_challan_id),
    KEY export_documents_order_idx (sales_order_id),
    CONSTRAINT export_documents_challan_fk FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id),
    CONSTRAINT export_documents_order_fk   FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT export_documents_type_chk CHECK (doc_type IN ('commercial_invoice','packing_list','coo','exp_form','bl','awb','ud','lc_document','insurance','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('export_documents');
    }
};
