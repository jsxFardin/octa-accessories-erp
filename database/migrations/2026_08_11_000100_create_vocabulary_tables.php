<?php

use App\Support\Schema\SqlScript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vocabularies become tables (docs/02a-schema.sql §1a).
 *
 * Product type, cut type, customer kind, inquiry source, order priority, product status,
 * defect severity and QC disposition were PHP enums behind CHECK constraints: an administrator
 * could read them in Setup and change nothing. They are now eight lookup tables with the
 * behaviour as columns, and the columns that used to be checked carry a foreign key instead —
 * the same refusal of an unknown value, without a release to add a known one.
 *
 * A database created from the schema document already has all of this, so every step is
 * guarded. This migration is what an existing database runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step asks before acting rather than the whole migration asking once: a database
        // created from the schema document already has the tables but not the rows, one built
        // by the earlier migrations has neither, and both have to end up in the same place.
        foreach (SqlScript::fromString(self::DDL)->statements() as $statement) {
            preg_match('/^CREATE\s+TABLE\s+`?(\w+)`?/i', ltrim($statement), $match);

            if (! Schema::hasTable($match[1])) {
                DB::unprepared($statement);
            }
        }

        $this->seed();

        foreach (self::LINKS as [$table, $column, $check, $foreign, $references]) {
            if (self::hasConstraint($check)) {
                DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$check}`");
            }

            if (! self::hasConstraint($foreign)) {
                DB::statement(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$foreign}` FOREIGN KEY (`{$column}`) REFERENCES {$references}",
                );
            }
        }

        // Carried no CHECK of its own, but the value means the same thing here as everywhere.
        if (! self::hasConstraint('certification_scopes_type_fk')) {
            DB::statement(
                'ALTER TABLE `certification_scopes` ADD CONSTRAINT `certification_scopes_type_fk` '
                .'FOREIGN KEY (`product_type`) REFERENCES product_types(code)',
            );
        }
    }

    /** Constraint names are schema-unique in MySQL, which makes this a straight lookup. */
    private static function hasConstraint(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND constraint_name = ?',
            [$name],
        ) !== null;
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_types')) {
            return;
        }

        if (self::hasConstraint('certification_scopes_type_fk')) {
            DB::statement('ALTER TABLE `certification_scopes` DROP FOREIGN KEY `certification_scopes_type_fk`');
        }

        foreach (self::LINKS as [$table, $column, $check, $foreign, $references]) {
            if (self::hasConstraint($foreign)) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$foreign}`");
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$foreign}`");
            }
        }

        foreach (self::CHECKS as $table => $definition) {
            DB::statement("ALTER TABLE `{$table}` ADD {$definition}");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (array_reverse(SqlScript::fromString(self::DDL)->tables()) as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** The rows the constraints are about to require, matching ReferenceDataSeeder. */
    private function seed(): void
    {
        $this->fill('product_types', [
            ['code' => 'woven', 'name' => 'Woven label', 'consumes_yarn' => true, 'consumes_sheets' => false, 'default_ink_lay_gsm' => null, 'requires_tool_per_colour' => false, 'sort_order' => 10],
            ['code' => 'flexo', 'name' => 'Flexo printed label', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => 1.6, 'requires_tool_per_colour' => true, 'sort_order' => 20],
            ['code' => 'screen', 'name' => 'Screen printed label', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => 8.0, 'requires_tool_per_colour' => true, 'sort_order' => 30],
            ['code' => 'heat_transfer', 'name' => 'Heat transfer label', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => 12.0, 'requires_tool_per_colour' => true, 'sort_order' => 40],
            ['code' => 'offset_tag', 'name' => 'Offset printed tag / ticket', 'consumes_yarn' => false, 'consumes_sheets' => true, 'default_ink_lay_gsm' => 1.1, 'requires_tool_per_colour' => true, 'sort_order' => 50],
            ['code' => 'thermal', 'name' => 'Thermal printed label', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => null, 'requires_tool_per_colour' => false, 'sort_order' => 60],
            ['code' => 'ribbon', 'name' => 'Printed ribbon', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => null, 'requires_tool_per_colour' => false, 'sort_order' => 70],
            ['code' => 'tape', 'name' => 'Printed tape', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => null, 'requires_tool_per_colour' => false, 'sort_order' => 80],
            ['code' => 'other', 'name' => 'Other', 'consumes_yarn' => false, 'consumes_sheets' => false, 'default_ink_lay_gsm' => null, 'requires_tool_per_colour' => false, 'sort_order' => 90],
        ]);

        $this->fill('cut_types', [
            ['code' => 'hot_cut', 'name' => 'Hot cut', 'default_cut_gap_mm' => 2.0, 'requires_tool' => false, 'sort_order' => 10],
            ['code' => 'ultrasonic', 'name' => 'Ultrasonic cut', 'default_cut_gap_mm' => 2.0, 'requires_tool' => false, 'sort_order' => 20],
            ['code' => 'laser', 'name' => 'Laser cut', 'default_cut_gap_mm' => 1.5, 'requires_tool' => false, 'sort_order' => 30],
            ['code' => 'die_cut', 'name' => 'Die cut', 'default_cut_gap_mm' => 3.0, 'requires_tool' => true, 'sort_order' => 40],
            ['code' => 'straight_cut', 'name' => 'Straight cut', 'default_cut_gap_mm' => 1.0, 'requires_tool' => false, 'sort_order' => 50],
        ]);

        $this->fill('customer_kinds', [
            ['code' => 'manufacturer', 'name' => 'Garment manufacturer', 'sort_order' => 10],
            ['code' => 'brand', 'name' => 'Brand', 'sort_order' => 20],
            ['code' => 'buying_house', 'name' => 'Buying house', 'sort_order' => 30],
            ['code' => 'trader', 'name' => 'Trader', 'sort_order' => 40],
        ]);

        $this->fill('inquiry_sources', [
            ['code' => 'email', 'name' => 'Email', 'sort_order' => 10],
            ['code' => 'phone', 'name' => 'Phone', 'sort_order' => 20],
            ['code' => 'visit', 'name' => 'Visit', 'sort_order' => 30],
            ['code' => 'buying_house', 'name' => 'Buying house', 'sort_order' => 40],
            ['code' => 'agent', 'name' => 'Agent', 'sort_order' => 50],
            ['code' => 'portal', 'name' => 'Customer portal', 'sort_order' => 60],
            ['code' => 'repeat', 'name' => 'Repeat order', 'sort_order' => 70],
        ]);

        $this->fill('order_priorities', [
            ['code' => 'low', 'name' => 'Low', 'priority_rank' => 20, 'sort_order' => 10],
            ['code' => 'normal', 'name' => 'Normal', 'priority_rank' => 50, 'sort_order' => 20],
            ['code' => 'high', 'name' => 'High', 'priority_rank' => 70, 'sort_order' => 30],
            ['code' => 'urgent', 'name' => 'Urgent', 'priority_rank' => 90, 'sort_order' => 40],
        ]);

        $this->fill('product_statuses', [
            ['code' => 'development', 'name' => 'Development', 'allows_ordering' => false, 'sort_order' => 10],
            ['code' => 'active', 'name' => 'Active', 'allows_ordering' => true, 'sort_order' => 20],
            ['code' => 'on_hold', 'name' => 'On hold', 'allows_ordering' => false, 'sort_order' => 30],
            ['code' => 'discontinued', 'name' => 'Discontinued', 'allows_ordering' => false, 'sort_order' => 40],
        ]);

        $this->fill('defect_severities', [
            ['code' => 'critical', 'name' => 'Critical — rejects the lot on its own', 'rejects_lot' => true, 'counts_toward_aql' => true, 'sort_order' => 10],
            ['code' => 'major', 'name' => 'Major — counted against the accept number', 'rejects_lot' => false, 'counts_toward_aql' => true, 'sort_order' => 20],
            ['code' => 'minor', 'name' => 'Minor — recorded, does not reject', 'rejects_lot' => false, 'counts_toward_aql' => false, 'sort_order' => 30],
        ]);

        $this->fill('qc_dispositions', [
            ['code' => 'rework', 'name' => 'Rework — back to an operation', 'returns_to_operation' => true, 'requires_customer_evidence' => false, 'regrades_stock' => false, 'writes_off_stock' => false, 'sort_order' => 10],
            ['code' => 'concession', 'name' => 'Concession — customer accepted', 'returns_to_operation' => false, 'requires_customer_evidence' => true, 'regrades_stock' => false, 'writes_off_stock' => false, 'sort_order' => 20],
            ['code' => 'downgrade', 'name' => 'Downgrade — second quality', 'returns_to_operation' => false, 'requires_customer_evidence' => false, 'regrades_stock' => true, 'writes_off_stock' => false, 'sort_order' => 30],
            ['code' => 'scrap', 'name' => 'Scrap — written off', 'returns_to_operation' => false, 'requires_customer_evidence' => false, 'regrades_stock' => false, 'writes_off_stock' => true, 'sort_order' => 40],
            ['code' => 'release', 'name' => 'Release — accepted as it stands', 'returns_to_operation' => false, 'requires_customer_evidence' => false, 'regrades_stock' => false, 'writes_off_stock' => false, 'sort_order' => 50],
        ]);
    }

    /**
     * Insert what is missing, leave what an administrator has already edited alone.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function fill(string $table, array $rows): void
    {
        $existing = DB::table($table)->pluck('code')->all();

        $missing = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! in_array($row['code'], $existing, true),
        ));

        if ($missing !== []) {
            DB::table($table)->insert($missing);
        }
    }

    /** [table, column, check constraint dropped, foreign key added, referenced column] */
    private const LINKS = [
        ['customers', 'kind', 'customers_kind_chk', 'customers_kind_fk', 'customer_kinds(code)'],
        ['routings', 'product_type', 'routings_type_chk', 'routings_type_fk', 'product_types(code)'],
        ['products', 'product_type', 'products_type_chk', 'products_type_fk', 'product_types(code)'],
        ['products', 'status', 'products_status_chk', 'products_status_fk', 'product_statuses(code)'],
        ['product_specs', 'cut_type', 'product_specs_cut_chk', 'product_specs_cut_fk', 'cut_types(code)'],
        ['inquiries', 'source', 'inquiries_source_chk', 'inquiries_source_fk', 'inquiry_sources(code)'],
        ['inquiry_lines', 'product_type', 'inquiry_lines_type_chk', 'inquiry_lines_type_fk', 'product_types(code)'],
        ['sales_orders', 'priority', 'sales_orders_priority_chk', 'sales_orders_priority_fk', 'order_priorities(code)'],
        ['defects', 'severity', 'defects_severity_chk', 'defects_severity_fk', 'defect_severities(code)'],
        ['qc_inspections', 'disposition', 'qc_inspections_disp_chk', 'qc_inspections_disp_fk', 'qc_dispositions(code)'],
        ['ncrs', 'severity', 'ncrs_severity_chk', 'ncrs_severity_fk', 'defect_severities(code)'],
    ];

    /** What `down()` puts back, verbatim from the schema document as it was. */
    private const CHECKS = [
        'customers' => "CONSTRAINT customers_kind_chk CHECK (kind IN ('manufacturer','brand','buying_house','trader'))",
        'routings' => "CONSTRAINT routings_type_chk CHECK (product_type IN ('woven','flexo','screen','heat_transfer','offset_tag','thermal','ribbon','tape','other'))",
        'product_specs' => "CONSTRAINT product_specs_cut_chk CHECK (cut_type IS NULL OR cut_type IN ('hot_cut','ultrasonic','laser','die_cut','straight_cut'))",
        'inquiries' => "CONSTRAINT inquiries_source_chk CHECK (source IS NULL OR source IN ('email','phone','visit','portal','agent','repeat'))",
        'inquiry_lines' => "CONSTRAINT inquiry_lines_type_chk CHECK (product_type IS NULL OR product_type IN ('woven','flexo','screen','heat_transfer','offset_tag','thermal','ribbon','tape','other'))",
        'sales_orders' => "CONSTRAINT sales_orders_priority_chk CHECK (priority IN ('low','normal','high','urgent'))",
        'defects' => "CONSTRAINT defects_severity_chk CHECK (severity IN ('critical','major','minor'))",
        'qc_inspections' => "CONSTRAINT qc_inspections_disp_chk CHECK (disposition IS NULL OR disposition IN ('rework','concession','downgrade','scrap','release'))",
        'ncrs' => "CONSTRAINT ncrs_severity_chk CHECK (severity IN ('critical','major','minor'))",
    ];

    private const DDL = <<<'SQL'
CREATE TABLE product_types (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                     VARCHAR(20)  NOT NULL,
    name                     VARCHAR(120) NOT NULL,
    consumes_yarn            BOOLEAN NOT NULL DEFAULT FALSE,
    consumes_sheets          BOOLEAN NOT NULL DEFAULT FALSE,
    default_ink_lay_gsm      DECIMAL(9,4),
    requires_tool_per_colour BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active                BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY product_types_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cut_types (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(20)  NOT NULL,
    name               VARCHAR(120) NOT NULL,
    default_cut_gap_mm DECIMAL(9,4) NOT NULL DEFAULT 0,
    requires_tool      BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY cut_types_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_kinds (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY customer_kinds_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inquiry_sources (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY inquiry_sources_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_priorities (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(10)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    priority_rank SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY order_priorities_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_statuses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    allows_ordering BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY product_statuses_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE defect_severities (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code              VARCHAR(10)  NOT NULL,
    name              VARCHAR(120) NOT NULL,
    rejects_lot       BOOLEAN NOT NULL DEFAULT FALSE,
    counts_toward_aql BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY defect_severities_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE qc_dispositions (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                       VARCHAR(20)  NOT NULL,
    name                       VARCHAR(120) NOT NULL,
    returns_to_operation       BOOLEAN NOT NULL DEFAULT FALSE,
    requires_customer_evidence BOOLEAN NOT NULL DEFAULT FALSE,
    regrades_stock             BOOLEAN NOT NULL DEFAULT FALSE,
    writes_off_stock           BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order                 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active                  BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY qc_dispositions_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
};
