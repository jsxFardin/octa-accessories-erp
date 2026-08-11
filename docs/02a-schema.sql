-- =====================================================================
-- Octa ERP — Label & Garment-Accessory Manufacturing ERP
-- Executable reference DDL — MySQL 8.0
--
-- Machine-checkable companion to 02-database-schema.md.
-- Verify with:
--   mysql -u <user> -p -e "CREATE DATABASE erpspec CHARACTER SET utf8mb4;"
--   mysql -u <user> -p erpspec < docs/02a-schema.sql
--
-- Conventions (see 00-overview §8):
--   money      DECIMAL(18,4)
--   quantity   DECIMAL(18,6)
--   percent    DECIMAL(9,4)      -- 5.5 means 5.5%
--   status     VARCHAR + CHECK   -- never MySQL ENUM (AD-5)
--   ids        BIGINT UNSIGNED AUTO_INCREMENT
--   timestamps DATETIME(3) storing UTC (Laravel convention)
--   engine     InnoDB / utf8mb4 / utf8mb4_0900_ai_ci
--
-- MySQL has no partial indexes. "One current/approved/active row" rules are
-- enforced by a STORED generated column that is NULL when the condition is
-- false, plus a UNIQUE index (MySQL does not collide on NULLs). Section 15
-- lists every such emulation.
-- =====================================================================

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 1. PLATFORM
-- =====================================================================

CREATE TABLE settings (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `key`        VARCHAR(150) NOT NULL,
    value        JSON         NOT NULL,
    group_name   VARCHAR(60)  NOT NULL DEFAULT 'general',
    description  VARCHAR(255),
    updated_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY settings_key_uq (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE number_sequences (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_type  VARCHAR(60) NOT NULL,
    series_key     VARCHAR(20) NOT NULL,
    prefix         VARCHAR(20) NOT NULL,
    next_number    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    padding        TINYINT UNSIGNED NOT NULL DEFAULT 5,
    UNIQUE KEY number_sequences_uq (document_type, series_key),
    CONSTRAINT number_sequences_next_chk    CHECK (next_number > 0),
    CONSTRAINT number_sequences_padding_chk CHECK (padding BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150) NOT NULL,
    email             VARCHAR(190) NOT NULL,
    password          VARCHAR(255) NOT NULL,
    remember_token    VARCHAR(100),
    email_verified_at DATETIME(3),
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    two_factor_secret VARCHAR(255),
    last_login_at     DATETIME(3),
    locale            VARCHAR(10) NOT NULL DEFAULT 'en',
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at        DATETIME(3),
    deleted_at        DATETIME(3),
    UNIQUE KEY users_email_uq (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE roles (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(80)  NOT NULL,
    label      VARCHAR(120) NOT NULL,
    is_system  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY roles_name_uq (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
    id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(120) NOT NULL,
    module VARCHAR(60)  NOT NULL,
    label  VARCHAR(150) NOT NULL,
    UNIQUE KEY permissions_name_uq (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permissions (
    role_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY role_permissions_perm_idx (permission_id),
    CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    CONSTRAINT role_permissions_perm_fk FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY user_roles_role_idx (role_id),
    CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_roles_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED,
    auditable_type VARCHAR(120) NOT NULL,
    auditable_id   BIGINT UNSIGNED NOT NULL,
    event          VARCHAR(30)  NOT NULL,
    old_values     JSON,
    new_values     JSON,
    ip_address     VARCHAR(45),
    user_agent     VARCHAR(255),
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY audit_logs_auditable_idx (auditable_type, auditable_id, created_at),
    KEY audit_logs_user_idx (user_id, created_at),
    CONSTRAINT audit_logs_user_fk  FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT audit_logs_event_chk CHECK (event IN ('created','updated','deleted','restored','status_changed','printed','exported','imported'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachable_type VARCHAR(120) NOT NULL,
    attachable_id   BIGINT UNSIGNED NOT NULL,
    collection      VARCHAR(60)  NOT NULL DEFAULT 'default',
    disk            VARCHAR(40)  NOT NULL DEFAULT 'local',
    path            VARCHAR(500) NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(120),
    size_bytes      BIGINT UNSIGNED,
    checksum_sha256 CHAR(64),
    uploaded_by     BIGINT UNSIGNED,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY attachments_owner_idx (attachable_type, attachable_id),
    CONSTRAINT attachments_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comments (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commentable_type VARCHAR(120) NOT NULL,
    commentable_id   BIGINT UNSIGNED NOT NULL,
    parent_id        BIGINT UNSIGNED,
    body             TEXT NOT NULL,
    is_external      BOOLEAN NOT NULL DEFAULT FALSE,
    created_by       BIGINT UNSIGNED,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY comments_owner_idx (commentable_type, commentable_id),
    KEY comments_parent_idx (parent_id),
    CONSTRAINT comments_parent_fk FOREIGN KEY (parent_id)  REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT comments_user_fk   FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 1a. VOCABULARIES
--
-- The dropdowns that used to be enums in PHP. Each one is a table an
-- administrator can add to, rename and retire, and the behaviour that used
-- to live in a `match` expression lives here as columns: whether a product
-- type consumes yarn (BR-9) or sheets (BR-11), the ink lay it defaults to
-- (BR-10), the tools a colour costs (BR-13), the cut gap a cut type adds
-- (BR-4), what a QC disposition does to the lot (BR-33).
--
-- The columns that carry these values are VARCHAR codes with a foreign key
-- to `code` rather than a CHECK constraint: same refusal of a value that
-- does not exist, without a schema change to add one.
--
-- A row added through Setup gets neutral behaviour — no yarn, no ink, no
-- sheets, no tool — which is the conservative default for a type the
-- costing rules have never seen. Change the flags and the calculators
-- follow immediately.
-- =====================================================================

CREATE TABLE product_types (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                     VARCHAR(20)  NOT NULL,
    name                     VARCHAR(120) NOT NULL,
    consumes_yarn            BOOLEAN NOT NULL DEFAULT FALSE,     -- BR-9
    consumes_sheets          BOOLEAN NOT NULL DEFAULT FALSE,     -- BR-11
    default_ink_lay_gsm      DECIMAL(9,4),                       -- BR-10, NULL = consumes no ink
    requires_tool_per_colour BOOLEAN NOT NULL DEFAULT FALSE,     -- BR-13
    sort_order               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active                BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY product_types_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cut_types (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(20)  NOT NULL,
    name               VARCHAR(120) NOT NULL,
    default_cut_gap_mm DECIMAL(9,4) NOT NULL DEFAULT 0,          -- BR-4
    requires_tool      BOOLEAN NOT NULL DEFAULT FALSE,           -- BR-13
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
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(10)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    -- The planning board sorts on this, not on the code: a new priority
    -- between normal and high needs a rank, not a release.
    priority_rank  SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY order_priorities_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_statuses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    -- Only a status that allows ordering may appear on a new order line;
    -- the rest stay readable on the documents that already used them.
    allows_ordering BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY product_statuses_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE defect_severities (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(10)  NOT NULL,
    name             VARCHAR(120) NOT NULL,
    rejects_lot      BOOLEAN NOT NULL DEFAULT FALSE,             -- BR-30
    counts_toward_aql BOOLEAN NOT NULL DEFAULT FALSE,            -- BR-31
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY defect_severities_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE qc_dispositions (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                      VARCHAR(20)  NOT NULL,
    name                      VARCHAR(120) NOT NULL,
    -- BR-33, as flags rather than a name the code switches on.
    returns_to_operation      BOOLEAN NOT NULL DEFAULT FALSE,
    requires_customer_evidence BOOLEAN NOT NULL DEFAULT FALSE,
    regrades_stock            BOOLEAN NOT NULL DEFAULT FALSE,
    writes_off_stock          BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order                SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active                 BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY qc_dispositions_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 2. ORGANISATION & MASTER DATA
-- =====================================================================

CREATE TABLE factory_units (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(150) NOT NULL,
    address   VARCHAR(255),
    timezone  VARCHAR(60)  NOT NULL DEFAULT 'Asia/Dhaka',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY factory_units_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE departments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    kind            VARCHAR(30)  NOT NULL,
    UNIQUE KEY departments_uq (factory_unit_id, code),
    CONSTRAINT departments_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT departments_kind_chk CHECK (kind IN ('design','plate','screen','weaving','printing','cutting','folding','qc','lab','store','packing','dispatch','maintenance','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    department_id   BIGINT UNSIGNED,
    code            VARCHAR(30)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    designation     VARCHAR(120),
    phone           VARCHAR(30),
    card_no         VARCHAR(40),
    skill_grade     VARCHAR(10),
    joined_on       DATE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY employees_user_uq (user_id),
    UNIQUE KEY employees_code_uq (code),
    UNIQUE KEY employees_card_uq (card_no),
    KEY employees_dept_idx (department_id),
    CONSTRAINT employees_user_fk FOREIGN KEY (user_id)         REFERENCES users(id),
    CONSTRAINT employees_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT employees_dept_fk FOREIGN KEY (department_id)   REFERENCES departments(id),
    CONSTRAINT employees_grade_chk CHECK (skill_grade IS NULL OR skill_grade IN ('A','B','C','trainee'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shifts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(80)  NOT NULL,
    starts_at       TIME NOT NULL,
    ends_at         TIME NOT NULL,
    break_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY shifts_uq (factory_unit_id, code),
    CONSTRAINT shifts_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE machine_groups (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(20)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    process_type VARCHAR(30)  NOT NULL,
    output_uom   VARCHAR(20)  NOT NULL DEFAULT 'metre',
    UNIQUE KEY machine_groups_code_uq (code),
    CONSTRAINT machine_groups_process_chk CHECK (process_type IN ('design','warping','weaving','flexo','screen','heat_transfer','offset','thermal','slitting','cutting','folding','curing','lamination','packing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE machines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id   BIGINT UNSIGNED NOT NULL,
    machine_group_id  BIGINT UNSIGNED NOT NULL,
    department_id     BIGINT UNSIGNED,
    code              VARCHAR(30)  NOT NULL,
    name              VARCHAR(120) NOT NULL,
    make              VARCHAR(80),
    model             VARCHAR(80),
    serial_no         VARCHAR(80),
    commissioned_on   DATE,
    web_width_mm      DECIMAL(9,2),
    max_colours       SMALLINT UNSIGNED,
    std_rate_per_hour DECIMAL(18,6),
    hourly_rate       DECIMAL(18,4) NOT NULL DEFAULT 0,
    kw_rating         DECIMAL(9,3),
    efficiency_pct    DECIMAL(9,4)  NOT NULL DEFAULT 85,
    status            VARCHAR(20)   NOT NULL DEFAULT 'available',
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY machines_code_uq (code),
    KEY machines_group_idx (machine_group_id, is_active),
    KEY machines_unit_idx (factory_unit_id),
    KEY machines_dept_idx (department_id),
    CONSTRAINT machines_unit_fk  FOREIGN KEY (factory_unit_id)  REFERENCES factory_units(id),
    CONSTRAINT machines_group_fk FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT machines_dept_fk  FOREIGN KEY (department_id)    REFERENCES departments(id),
    CONSTRAINT machines_status_chk CHECK (status IN ('available','running','maintenance','breakdown','retired')),
    CONSTRAINT machines_eff_chk    CHECK (efficiency_pct > 0 AND efficiency_pct <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE warehouses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    kind            VARCHAR(30)  NOT NULL,
    is_nettable     BOOLEAN NOT NULL DEFAULT TRUE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY warehouses_code_uq (code),
    KEY warehouses_unit_idx (factory_unit_id),
    CONSTRAINT warehouses_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT warehouses_kind_chk CHECK (kind IN ('raw_material','ink_chemical','tool','wip','finished_goods','packing','scrap','transit'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bins (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(30) NOT NULL,
    description  VARCHAR(150),
    UNIQUE KEY bins_uq (warehouse_id, code),
    CONSTRAINT bins_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE uoms (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20) NOT NULL,
    name      VARCHAR(60) NOT NULL,
    dimension VARCHAR(20) NOT NULL,
    UNIQUE KEY uoms_code_uq (code),
    CONSTRAINT uoms_dimension_chk CHECK (dimension IN ('length','mass','area','volume','count','time'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE currencies (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code     CHAR(3)     NOT NULL,
    name     VARCHAR(60) NOT NULL,
    symbol   VARCHAR(10),
    is_base  BOOLEAN NOT NULL DEFAULT FALSE,
    -- emulation of a partial unique index: only one base currency (§15)
    base_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(is_base, 1, NULL)) STORED,
    UNIQUE KEY currencies_code_uq (code),
    UNIQUE KEY currencies_one_base_uq (base_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exchange_rates (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    currency_id  BIGINT UNSIGNED NOT NULL,
    effective_on DATE NOT NULL,
    rate_to_base DECIMAL(18,8) NOT NULL,
    UNIQUE KEY exchange_rates_uq (currency_id, effective_on),
    CONSTRAINT exchange_rates_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT exchange_rates_rate_chk CHECK (rate_to_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE taxes (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(80)  NOT NULL,
    rate_pct  DECIMAL(9,4) NOT NULL,
    kind      VARCHAR(20)  NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY taxes_code_uq (code),
    CONSTRAINT taxes_rate_chk CHECK (rate_pct >= 0),
    CONSTRAINT taxes_kind_chk CHECK (kind IN ('vat','ait','sd','withholding'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_terms (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20) NOT NULL,
    name       VARCHAR(80) NOT NULL,
    net_days   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_lc      BOOLEAN NOT NULL DEFAULT FALSE,
    is_advance BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY payment_terms_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE item_categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id  BIGINT UNSIGNED,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    item_class VARCHAR(20)  NOT NULL,
    UNIQUE KEY item_categories_code_uq (code),
    KEY item_categories_parent_idx (parent_id),
    CONSTRAINT item_categories_parent_fk FOREIGN KEY (parent_id) REFERENCES item_categories(id),
    CONSTRAINT item_categories_class_chk CHECK (item_class IN ('yarn','ribbon','tape','ink','chemical','paper','film','adhesive','tool_stock','packing','spare','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suppliers (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    country         VARCHAR(60),
    address         VARCHAR(255),
    email           VARCHAR(190),
    phone           VARCHAR(30),
    currency_id     BIGINT UNSIGNED,
    payment_term_id BIGINT UNSIGNED,
    lead_time_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_approved     BOOLEAN NOT NULL DEFAULT FALSE,
    rating          DECIMAL(4,2),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at      DATETIME(3),
    UNIQUE KEY suppliers_code_uq (code),
    KEY suppliers_currency_idx (currency_id),
    KEY suppliers_term_idx (payment_term_id),
    CONSTRAINT suppliers_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT suppliers_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_contacts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    designation VARCHAR(120),
    email       VARCHAR(190),
    phone       VARCHAR(30),
    is_primary  BOOLEAN NOT NULL DEFAULT FALSE,
    KEY supplier_contacts_supplier_idx (supplier_id),
    CONSTRAINT supplier_contacts_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE items (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_category_id    BIGINT UNSIGNED NOT NULL,
    code                VARCHAR(40)  NOT NULL,
    name                VARCHAR(180) NOT NULL,
    description         VARCHAR(500),
    base_uom_id         BIGINT UNSIGNED NOT NULL,
    purchase_uom_id     BIGINT UNSIGNED,
    default_supplier_id BIGINT UNSIGNED,
    min_order_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    order_multiple      DECIMAL(18,6) NOT NULL DEFAULT 1,
    reorder_level       DECIMAL(18,6) NOT NULL DEFAULT 0,
    safety_days         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    std_rate            DECIMAL(18,4) NOT NULL DEFAULT 0,
    avg_rate            DECIMAL(18,4) NOT NULL DEFAULT 0,
    density             DECIMAL(18,6),
    gsm                 DECIMAL(9,3),
    ink_lay_gsm         DECIMAL(9,3),
    shade_code          VARCHAR(40),
    is_lot_tracked      BOOLEAN NOT NULL DEFAULT TRUE,
    is_shade_critical   BOOLEAN NOT NULL DEFAULT FALSE,
    has_expiry          BOOLEAN NOT NULL DEFAULT FALSE,
    shelf_life_days     SMALLINT UNSIGNED,
    attributes          JSON NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at          DATETIME(3),
    UNIQUE KEY items_code_uq (code),
    KEY items_category_idx (item_category_id, is_active),
    KEY items_base_uom_idx (base_uom_id),
    KEY items_purchase_uom_idx (purchase_uom_id),
    KEY items_supplier_idx (default_supplier_id),
    CONSTRAINT items_category_fk FOREIGN KEY (item_category_id)    REFERENCES item_categories(id),
    CONSTRAINT items_base_uom_fk FOREIGN KEY (base_uom_id)         REFERENCES uoms(id),
    CONSTRAINT items_pur_uom_fk  FOREIGN KEY (purchase_uom_id)     REFERENCES uoms(id),
    CONSTRAINT items_supplier_fk FOREIGN KEY (default_supplier_id) REFERENCES suppliers(id),
    CONSTRAINT items_multiple_chk CHECK (order_multiple > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE uom_conversions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id     BIGINT UNSIGNED,                 -- NULL = global conversion
    from_uom_id BIGINT UNSIGNED NOT NULL,
    to_uom_id   BIGINT UNSIGNED NOT NULL,
    factor      DECIMAL(18,8) NOT NULL,
    -- emulation of a unique index over a nullable column (§15)
    item_key    BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(item_id, 0)) STORED,
    UNIQUE KEY uom_conversions_uq (item_key, from_uom_id, to_uom_id),
    KEY uom_conversions_from_idx (from_uom_id),
    KEY uom_conversions_to_idx (to_uom_id),
    KEY uom_conversions_item_idx (item_id),
    CONSTRAINT uom_conversions_item_fk FOREIGN KEY (item_id)     REFERENCES items(id),   -- no CASCADE: item_id feeds item_key (§15)
    CONSTRAINT uom_conversions_from_fk FOREIGN KEY (from_uom_id) REFERENCES uoms(id),
    CONSTRAINT uom_conversions_to_fk   FOREIGN KEY (to_uom_id)   REFERENCES uoms(id),
    CONSTRAINT uom_conversions_factor_chk CHECK (factor > 0),
    CONSTRAINT uom_conversions_diff_chk   CHECK (from_uom_id <> to_uom_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id    BIGINT UNSIGNED NOT NULL,
    item_id        BIGINT UNSIGNED NOT NULL,
    supplier_code  VARCHAR(60),
    last_rate      DECIMAL(18,4),
    currency_id    BIGINT UNSIGNED,
    lead_time_days SMALLINT UNSIGNED,
    moq            DECIMAL(18,6),
    UNIQUE KEY supplier_items_uq (supplier_id, item_id),
    KEY supplier_items_item_idx (item_id),
    KEY supplier_items_currency_idx (currency_id),
    CONSTRAINT supplier_items_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT supplier_items_item_fk     FOREIGN KEY (item_id)     REFERENCES items(id) ON DELETE CASCADE,
    CONSTRAINT supplier_items_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE buying_houses (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(150) NOT NULL,
    country   VARCHAR(60),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY buying_houses_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE agents (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(20)  NOT NULL,
    name           VARCHAR(150) NOT NULL,
    commission_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY agents_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(20)  NOT NULL,
    name                VARCHAR(180) NOT NULL,
    kind                VARCHAR(20)  NOT NULL DEFAULT 'manufacturer',
    buying_house_id     BIGINT UNSIGNED,
    agent_id            BIGINT UNSIGNED,
    currency_id         BIGINT UNSIGNED,
    payment_term_id     BIGINT UNSIGNED,
    credit_limit        DECIMAL(18,4) NOT NULL DEFAULT 0,
    min_order_value     DECIMAL(18,4) NOT NULL DEFAULT 0,
    over_tolerance_pct  DECIMAL(9,4)  NOT NULL DEFAULT 5,
    under_tolerance_pct DECIMAL(9,4)  NOT NULL DEFAULT 5,
    bin_no              VARCHAR(40),
    tin_no              VARCHAR(40),
    email               VARCHAR(190),
    phone               VARCHAR(30),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at          DATETIME(3),
    UNIQUE KEY customers_code_uq (code),
    KEY customers_bh_idx (buying_house_id),
    KEY customers_agent_idx (agent_id),
    KEY customers_currency_idx (currency_id),
    KEY customers_term_idx (payment_term_id),
    CONSTRAINT customers_bh_fk       FOREIGN KEY (buying_house_id) REFERENCES buying_houses(id),
    CONSTRAINT customers_agent_fk    FOREIGN KEY (agent_id)        REFERENCES agents(id),
    CONSTRAINT customers_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT customers_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id),
    CONSTRAINT customers_kind_fk FOREIGN KEY (kind) REFERENCES customer_kinds(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_contacts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id    BIGINT UNSIGNED NOT NULL,
    name           VARCHAR(150) NOT NULL,
    designation    VARCHAR(120),
    email          VARCHAR(190),
    phone          VARCHAR(30),
    is_primary     BOOLEAN NOT NULL DEFAULT FALSE,
    portal_user_id BIGINT UNSIGNED,
    KEY customer_contacts_customer_idx (customer_id),
    KEY customer_contacts_user_idx (portal_user_id),
    CONSTRAINT customer_contacts_customer_fk FOREIGN KEY (customer_id)    REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT customer_contacts_user_fk     FOREIGN KEY (portal_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_addresses (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    label        VARCHAR(80)  NOT NULL,
    kind         VARCHAR(20)  NOT NULL,
    line1        VARCHAR(255) NOT NULL,
    line2        VARCHAR(255),
    city         VARCHAR(80),
    district     VARCHAR(80),
    postcode     VARCHAR(20),
    country      VARCHAR(60) NOT NULL DEFAULT 'Bangladesh',
    transit_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    route_zone   VARCHAR(60),
    is_default   BOOLEAN NOT NULL DEFAULT FALSE,
    KEY customer_addresses_customer_idx (customer_id),
    KEY customer_addresses_zone_idx (route_zone),
    CONSTRAINT customer_addresses_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT customer_addresses_kind_chk CHECK (kind IN ('billing','delivery','both'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE brands (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY brands_code_uq (code),
    KEY brands_customer_idx (customer_id),
    CONSTRAINT brands_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE price_lists (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    currency_id BIGINT UNSIGNED NOT NULL,
    valid_from  DATE NOT NULL,
    valid_to    DATE,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY price_lists_code_uq (code),
    KEY price_lists_customer_idx (customer_id),
    KEY price_lists_currency_idx (currency_id),
    CONSTRAINT price_lists_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT price_lists_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT price_lists_valid_chk CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE price_list_lines (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    price_list_id BIGINT UNSIGNED NOT NULL,
    product_id    BIGINT UNSIGNED,
    description   VARCHAR(255),
    min_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate_per_m    DECIMAL(18,4) NOT NULL,
    KEY price_list_lines_list_idx (price_list_id),
    KEY price_list_lines_product_idx (product_id),
    CONSTRAINT price_list_lines_list_fk FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 3. PRODUCT, ARTWORK, BOM, ROUTING, TOOLING
-- =====================================================================

CREATE TABLE routings (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(30)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    product_type VARCHAR(20)  NOT NULL,
    max_lot_size DECIMAL(18,6),
    is_default   BOOLEAN NOT NULL DEFAULT FALSE,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY routings_code_uq (code),
    CONSTRAINT routings_type_fk FOREIGN KEY (product_type) REFERENCES product_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE routing_operations (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    routing_id        BIGINT UNSIGNED NOT NULL,
    sequence_no       SMALLINT UNSIGNED NOT NULL,
    code              VARCHAR(30)  NOT NULL,
    name              VARCHAR(120) NOT NULL,
    machine_group_id  BIGINT UNSIGNED,
    department_id     BIGINT UNSIGNED,
    std_rate_per_hour DECIMAL(18,6),
    setup_minutes     DECIMAL(9,2)  NOT NULL DEFAULT 0,
    setup_qty         DECIMAL(18,6) NOT NULL DEFAULT 0,
    wastage_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,
    manning_level     DECIMAL(9,4)  NOT NULL DEFAULT 1,
    consumes_web      BOOLEAN NOT NULL DEFAULT TRUE,
    allow_parallel    BOOLEAN NOT NULL DEFAULT FALSE,
    requires_qc       BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY routing_operations_uq (routing_id, sequence_no),
    KEY routing_operations_group_idx (machine_group_id),
    KEY routing_operations_dept_idx (department_id),
    CONSTRAINT routing_operations_routing_fk FOREIGN KEY (routing_id)        REFERENCES routings(id) ON DELETE CASCADE,
    CONSTRAINT routing_operations_group_fk   FOREIGN KEY (machine_group_id)  REFERENCES machine_groups(id),
    CONSTRAINT routing_operations_dept_fk    FOREIGN KEY (department_id)     REFERENCES departments(id),
    CONSTRAINT routing_operations_seq_chk     CHECK (sequence_no > 0),
    CONSTRAINT routing_operations_wastage_chk CHECK (wastage_pct >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id          BIGINT UNSIGNED NOT NULL,
    brand_id             BIGINT UNSIGNED,
    routing_id           BIGINT UNSIGNED,
    code                 VARCHAR(40)  NOT NULL,
    customer_style_ref   VARCHAR(80),
    name                 VARCHAR(180) NOT NULL,
    product_type         VARCHAR(20)  NOT NULL,
    is_running_programme BOOLEAN NOT NULL DEFAULT FALSE,
    annual_forecast_qty  DECIMAL(18,6),
    status               VARCHAR(20)  NOT NULL DEFAULT 'development',
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    created_at           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by           BIGINT UNSIGNED,
    deleted_at           DATETIME(3),
    UNIQUE KEY products_code_uq (code),
    KEY products_customer_idx (customer_id, is_active),
    KEY products_brand_idx (brand_id),
    KEY products_routing_idx (routing_id),
    KEY products_creator_idx (created_by),
    CONSTRAINT products_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT products_brand_fk    FOREIGN KEY (brand_id)    REFERENCES brands(id),
    CONSTRAINT products_routing_fk  FOREIGN KEY (routing_id)  REFERENCES routings(id),
    CONSTRAINT products_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT products_type_fk   FOREIGN KEY (product_type) REFERENCES product_types(code),
    CONSTRAINT products_status_fk FOREIGN KEY (status)       REFERENCES product_statuses(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE price_list_lines
    ADD CONSTRAINT price_list_lines_product_fk FOREIGN KEY (product_id) REFERENCES products(id);

CREATE TABLE product_specs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id         BIGINT UNSIGNED NOT NULL,
    version_no         SMALLINT UNSIGNED NOT NULL,
    status             VARCHAR(20) NOT NULL DEFAULT 'draft',
    -- geometry
    label_width_mm     DECIMAL(9,2) NOT NULL,
    label_height_mm    DECIMAL(9,2) NOT NULL,
    web_width_mm       DECIMAL(9,2),
    selvedge_mm        DECIMAL(9,2) NOT NULL DEFAULT 0,
    lane_gap_mm        DECIMAL(9,2) NOT NULL DEFAULT 0,
    cut_gap_mm         DECIMAL(9,2) NOT NULL DEFAULT 2,
    ends               SMALLINT UNSIGNED,
    -- construction
    base_material      VARCHAR(60),
    fabric_gsm         DECIMAL(9,3),
    warp_ratio         DECIMAL(9,4) NOT NULL DEFAULT 0.60,
    colours            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    colour_list        JSON NOT NULL,
    cut_type           VARCHAR(20),
    fold_type          VARCHAR(20),
    finish             VARCHAR(120),
    coverage_pct       DECIMAL(9,4) NOT NULL DEFAULT 40,
    -- packing defaults, BR-12
    bundle_size        INT UNSIGNED NOT NULL DEFAULT 500,
    bundles_per_carton INT UNSIGNED NOT NULL DEFAULT 20,
    -- compliance content
    care_symbols       JSON NOT NULL,
    fibre_composition  VARCHAR(255),
    country_of_origin  VARCHAR(60),
    claims             JSON NOT NULL,
    -- type-specific free attributes (AD-2)
    attributes         JSON NOT NULL,
    notes              TEXT,
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by         BIGINT UNSIGNED,
    -- P2: exactly one 'current' spec per product (§15)
    current_key        BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'current', product_id, NULL)) STORED,
    UNIQUE KEY product_specs_uq (product_id, version_no),
    UNIQUE KEY product_specs_one_current_uq (current_key),
    KEY product_specs_creator_idx (created_by),
    CONSTRAINT product_specs_product_fk FOREIGN KEY (product_id) REFERENCES products(id),   -- no CASCADE: product_id feeds current_key (§15)
    CONSTRAINT product_specs_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT product_specs_version_chk CHECK (version_no > 0),
    CONSTRAINT product_specs_status_chk  CHECK (status IN ('draft','current','superseded')),
    CONSTRAINT product_specs_width_chk   CHECK (label_width_mm > 0),
    CONSTRAINT product_specs_height_chk  CHECK (label_height_mm > 0),
    CONSTRAINT product_specs_ends_chk    CHECK (ends IS NULL OR ends >= 1),
    CONSTRAINT product_specs_warp_chk    CHECK (warp_ratio > 0 AND warp_ratio < 1),
    CONSTRAINT product_specs_colours_chk CHECK (colours >= 1),
    CONSTRAINT product_specs_bundle_chk  CHECK (bundle_size > 0 AND bundles_per_carton > 0),
    CONSTRAINT product_specs_cut_fk      FOREIGN KEY (cut_type) REFERENCES cut_types(code),
    CONSTRAINT product_specs_fold_chk    CHECK (fold_type IS NULL OR fold_type IN ('flat','centre_fold','end_fold','loop','mitre','manhattan','book_cover'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE artworks (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id  BIGINT UNSIGNED NOT NULL,
    code        VARCHAR(40)  NOT NULL,
    title       VARCHAR(180) NOT NULL,
    designer_id BIGINT UNSIGNED,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY artworks_code_uq (code),
    KEY artworks_product_idx (product_id),
    KEY artworks_designer_idx (designer_id),
    CONSTRAINT artworks_product_fk  FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT artworks_designer_fk FOREIGN KEY (designer_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE artwork_versions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    artwork_id       BIGINT UNSIGNED NOT NULL,
    version_no       SMALLINT UNSIGNED NOT NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'draft',
    file_path        VARCHAR(500) NOT NULL,
    file_format      VARCHAR(10),
    preview_path     VARCHAR(500),
    checksum_sha256  CHAR(64),
    submitted_at     DATETIME(3),
    approved_at      DATETIME(3),
    approved_by      BIGINT UNSIGNED,
    customer_ref     VARCHAR(180),
    rejection_reason VARCHAR(500),
    created_by       BIGINT UNSIGNED,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    -- A2 / Gate 1: at most one approved version per artwork (§15)
    approved_key     BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'approved', artwork_id, NULL)) STORED,
    UNIQUE KEY artwork_versions_uq (artwork_id, version_no),
    UNIQUE KEY artwork_versions_one_approved_uq (approved_key),
    KEY artwork_versions_approver_idx (approved_by),
    KEY artwork_versions_creator_idx (created_by),
    CONSTRAINT artwork_versions_artwork_fk  FOREIGN KEY (artwork_id)  REFERENCES artworks(id),   -- no CASCADE: artwork_id feeds approved_key (§15)
    CONSTRAINT artwork_versions_approver_fk FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT artwork_versions_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT artwork_versions_version_chk CHECK (version_no > 0),
    CONSTRAINT artwork_versions_status_chk  CHECK (status IN ('draft','submitted','approved','rejected','superseded')),
    CONSTRAINT artwork_versions_format_chk  CHECK (file_format IS NULL OR file_format IN ('ai','eps','pdf','cdr','psd','png','jpg','svg'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE boms (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id      BIGINT UNSIGNED NOT NULL,
    product_spec_id BIGINT UNSIGNED,
    version_no      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    base_qty        DECIMAL(18,6) NOT NULL DEFAULT 1000,
    notes           TEXT,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    -- exactly one active BOM per product (§15)
    active_key      BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'active', product_id, NULL)) STORED,
    UNIQUE KEY boms_uq (product_id, version_no),
    UNIQUE KEY boms_one_active_uq (active_key),
    KEY boms_spec_idx (product_spec_id),
    KEY boms_creator_idx (created_by),
    CONSTRAINT boms_product_fk FOREIGN KEY (product_id)      REFERENCES products(id),   -- no CASCADE: product_id feeds active_key (§15)
    CONSTRAINT boms_spec_fk    FOREIGN KEY (product_spec_id) REFERENCES product_specs(id),
    CONSTRAINT boms_creator_fk FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT boms_version_chk CHECK (version_no > 0),
    CONSTRAINT boms_status_chk  CHECK (status IN ('draft','active','superseded'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bom_lines (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bom_id       BIGINT UNSIGNED NOT NULL,
    item_id      BIGINT UNSIGNED NOT NULL,
    uom_id       BIGINT UNSIGNED NOT NULL,
    qty_per_base DECIMAL(18,6) NOT NULL,
    wastage_pct  DECIMAL(9,4)  NOT NULL DEFAULT 0,
    colour_index SMALLINT UNSIGNED,
    is_optional  BOOLEAN NOT NULL DEFAULT FALSE,
    formula_ref  VARCHAR(20),
    notes        VARCHAR(255),
    -- unique per (bom, item, colour); colour_index NULL means "all colours" (§15)
    colour_key   SMALLINT UNSIGNED GENERATED ALWAYS AS (IFNULL(colour_index, 0)) STORED,
    UNIQUE KEY bom_lines_uq (bom_id, item_id, colour_key),
    KEY bom_lines_item_idx (item_id),
    KEY bom_lines_uom_idx (uom_id),
    CONSTRAINT bom_lines_bom_fk  FOREIGN KEY (bom_id)  REFERENCES boms(id) ON DELETE CASCADE,
    CONSTRAINT bom_lines_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT bom_lines_uom_fk  FOREIGN KEY (uom_id)  REFERENCES uoms(id),
    CONSTRAINT bom_lines_qty_chk CHECK (qty_per_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tools (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_spec_id  BIGINT UNSIGNED,
    kind             VARCHAR(20) NOT NULL,
    code             VARCHAR(40) NOT NULL,
    colour_index     SMALLINT UNSIGNED,
    location         VARCHAR(80),
    made_on          DATE,
    cost             DECIMAL(18,4) NOT NULL DEFAULT 0,
    life_impressions BIGINT UNSIGNED,
    used_impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status           VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY tools_code_uq (code),
    KEY tools_spec_idx (product_spec_id, status),
    CONSTRAINT tools_spec_fk FOREIGN KEY (product_spec_id) REFERENCES product_specs(id),
    CONSTRAINT tools_kind_chk   CHECK (kind IN ('flexo_plate','screen','offset_plate','cutting_die','embossing_die','cad_pattern')),
    CONSTRAINT tools_status_chk CHECK (status IN ('in_production','available','in_use','worn','scrapped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 4. CRM & SALES
-- =====================================================================

CREATE TABLE inquiries (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    customer_id         BIGINT UNSIGNED NOT NULL,
    customer_contact_id BIGINT UNSIGNED,
    brand_id            BIGINT UNSIGNED,
    inquiry_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    required_by         DATE,
    source              VARCHAR(20),
    merchandiser_id     BIGINT UNSIGNED,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    lost_reason         VARCHAR(255),
    notes               TEXT,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    updated_at          DATETIME(3),
    UNIQUE KEY inquiries_number_uq (number),
    KEY inquiries_open_idx (status, customer_id, inquiry_date),
    KEY inquiries_contact_idx (customer_contact_id),
    KEY inquiries_brand_idx (brand_id),
    KEY inquiries_merch_idx (merchandiser_id),
    KEY inquiries_creator_idx (created_by),
    CONSTRAINT inquiries_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT inquiries_contact_fk  FOREIGN KEY (customer_contact_id) REFERENCES customer_contacts(id),
    CONSTRAINT inquiries_brand_fk    FOREIGN KEY (brand_id)            REFERENCES brands(id),
    CONSTRAINT inquiries_merch_fk    FOREIGN KEY (merchandiser_id)     REFERENCES employees(id),
    CONSTRAINT inquiries_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT inquiries_source_fk FOREIGN KEY (source) REFERENCES inquiry_sources(code),
    CONSTRAINT inquiries_status_chk CHECK (status IN ('draft','open','quoted','won','lost','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inquiry_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inquiry_id        BIGINT UNSIGNED NOT NULL,
    line_no           SMALLINT UNSIGNED NOT NULL,
    product_id        BIGINT UNSIGNED,
    description       VARCHAR(255) NOT NULL,
    product_type      VARCHAR(20),
    qty               DECIMAL(18,6) NOT NULL,
    target_rate_per_m DECIMAL(18,4),
    notes             VARCHAR(255),
    UNIQUE KEY inquiry_lines_uq (inquiry_id, line_no),
    KEY inquiry_lines_product_idx (product_id),
    CONSTRAINT inquiry_lines_inquiry_fk FOREIGN KEY (inquiry_id) REFERENCES inquiries(id) ON DELETE CASCADE,
    CONSTRAINT inquiry_lines_product_fk FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT inquiry_lines_qty_chk  CHECK (qty > 0),
    CONSTRAINT inquiry_lines_type_fk FOREIGN KEY (product_type) REFERENCES product_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quotations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    revision_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    inquiry_id      BIGINT UNSIGNED,
    customer_id     BIGINT UNSIGNED NOT NULL,
    quotation_date  DATE NOT NULL DEFAULT (CURRENT_DATE),
    valid_until     DATE,
    currency_id     BIGINT UNSIGNED NOT NULL,
    exchange_rate   DECIMAL(18,8) NOT NULL DEFAULT 1,
    payment_term_id BIGINT UNSIGNED,
    merchandiser_id BIGINT UNSIGNED,
    subtotal        DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(18,4) NOT NULL DEFAULT 0,
    total           DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    sent_at         DATETIME(3),
    decided_at      DATETIME(3),
    reject_reason   VARCHAR(500),
    terms           TEXT,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY quotations_number_uq (number, revision_no),
    KEY quotations_open_idx (status, customer_id, quotation_date),
    KEY quotations_inquiry_idx (inquiry_id),
    KEY quotations_currency_idx (currency_id),
    KEY quotations_term_idx (payment_term_id),
    KEY quotations_merch_idx (merchandiser_id),
    KEY quotations_creator_idx (created_by),
    CONSTRAINT quotations_inquiry_fk  FOREIGN KEY (inquiry_id)      REFERENCES inquiries(id),
    CONSTRAINT quotations_customer_fk FOREIGN KEY (customer_id)     REFERENCES customers(id),
    CONSTRAINT quotations_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT quotations_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id),
    CONSTRAINT quotations_merch_fk    FOREIGN KEY (merchandiser_id) REFERENCES employees(id),
    CONSTRAINT quotations_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT quotations_status_chk CHECK (status IN ('draft','sent','accepted','rejected','expired','revised','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quotation_lines (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quotation_id    BIGINT UNSIGNED NOT NULL,
    line_no         SMALLINT UNSIGNED NOT NULL,
    product_id      BIGINT UNSIGNED,
    product_spec_id BIGINT UNSIGNED,
    description     VARCHAR(255) NOT NULL,
    qty             DECIMAL(18,6) NOT NULL,
    rate_per_m      DECIMAL(18,4) NOT NULL,
    tooling_charge  DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_id          BIGINT UNSIGNED,
    line_total      DECIMAL(18,4) NOT NULL DEFAULT 0,
    lead_time_days  SMALLINT UNSIGNED,
    UNIQUE KEY quotation_lines_uq (quotation_id, line_no),
    KEY quotation_lines_product_idx (product_id),
    KEY quotation_lines_spec_idx (product_spec_id),
    KEY quotation_lines_tax_idx (tax_id),
    CONSTRAINT quotation_lines_quotation_fk FOREIGN KEY (quotation_id)    REFERENCES quotations(id) ON DELETE CASCADE,
    CONSTRAINT quotation_lines_product_fk   FOREIGN KEY (product_id)      REFERENCES products(id),
    CONSTRAINT quotation_lines_spec_fk      FOREIGN KEY (product_spec_id) REFERENCES product_specs(id),
    CONSTRAINT quotation_lines_tax_fk       FOREIGN KEY (tax_id)          REFERENCES taxes(id),
    CONSTRAINT quotation_lines_qty_chk  CHECK (qty > 0),
    CONSTRAINT quotation_lines_rate_chk CHECK (rate_per_m >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cost_sheets (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quotation_line_id BIGINT UNSIGNED,
    product_id        BIGINT UNSIGNED,
    product_spec_id   BIGINT UNSIGNED,
    basis_qty         DECIMAL(18,6) NOT NULL,
    gross_metres      DECIMAL(18,6),
    total_wastage_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    overhead_pct      DECIMAL(9,4) NOT NULL DEFAULT 12,
    admin_pct         DECIMAL(9,4) NOT NULL DEFAULT 5,
    margin_pct        DECIMAL(9,4) NOT NULL DEFAULT 20,
    material_cost     DECIMAL(18,4) NOT NULL DEFAULT 0,
    tooling_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    machine_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    labour_cost       DECIMAL(18,4) NOT NULL DEFAULT 0,
    energy_cost       DECIMAL(18,4) NOT NULL DEFAULT 0,
    packing_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    other_cost        DECIMAL(18,4) NOT NULL DEFAULT 0,
    overhead_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    total_cost        DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit_cost         DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate_per_m        DECIMAL(18,4) NOT NULL DEFAULT 0,
    is_locked         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        BIGINT UNSIGNED,
    KEY cost_sheets_qline_idx (quotation_line_id),
    KEY cost_sheets_product_idx (product_id),
    KEY cost_sheets_spec_idx (product_spec_id),
    KEY cost_sheets_creator_idx (created_by),
    CONSTRAINT cost_sheets_qline_fk   FOREIGN KEY (quotation_line_id) REFERENCES quotation_lines(id) ON DELETE CASCADE,
    CONSTRAINT cost_sheets_product_fk FOREIGN KEY (product_id)        REFERENCES products(id),
    CONSTRAINT cost_sheets_spec_fk    FOREIGN KEY (product_spec_id)   REFERENCES product_specs(id),
    CONSTRAINT cost_sheets_creator_fk FOREIGN KEY (created_by)        REFERENCES users(id),
    CONSTRAINT cost_sheets_basis_chk  CHECK (basis_qty > 0),
    CONSTRAINT cost_sheets_margin_chk CHECK (margin_pct < 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cost_sheet_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cost_sheet_id    BIGINT UNSIGNED NOT NULL,
    sequence_no      SMALLINT UNSIGNED NOT NULL,
    cost_type        VARCHAR(30) NOT NULL,
    item_id          BIGINT UNSIGNED,
    machine_group_id BIGINT UNSIGNED,
    description      VARCHAR(255) NOT NULL,
    basis_uom        VARCHAR(20),
    qty              DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate             DECIMAL(18,6) NOT NULL DEFAULT 0,
    amount           DECIMAL(18,4) NOT NULL DEFAULT 0,
    formula_ref      VARCHAR(20),
    UNIQUE KEY cost_sheet_lines_uq (cost_sheet_id, sequence_no),
    KEY cost_sheet_lines_item_idx (item_id),
    KEY cost_sheet_lines_group_idx (machine_group_id),
    CONSTRAINT cost_sheet_lines_sheet_fk FOREIGN KEY (cost_sheet_id)    REFERENCES cost_sheets(id) ON DELETE CASCADE,
    CONSTRAINT cost_sheet_lines_item_fk  FOREIGN KEY (item_id)          REFERENCES items(id),
    CONSTRAINT cost_sheet_lines_group_fk FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT cost_sheet_lines_type_chk CHECK (cost_type IN (
        'material_yarn','material_ribbon','material_ink','material_chemical',
        'material_paper','material_film','material_packing','tooling','machine',
        'labour','energy','outsourcing','freight','overhead','margin','minimum_charge','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_orders (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    revision_no         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    quotation_id        BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    customer_po_no      VARCHAR(80),
    order_date          DATE NOT NULL DEFAULT (CURRENT_DATE),
    delivery_date       DATE,
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    payment_term_id     BIGINT UNSIGNED,
    billing_address_id  BIGINT UNSIGNED,
    delivery_address_id BIGINT UNSIGNED,
    merchandiser_id     BIGINT UNSIGNED,
    factory_unit_id     BIGINT UNSIGNED,
    subtotal            DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total               DECIMAL(18,4) NOT NULL DEFAULT 0,
    priority            VARCHAR(10) NOT NULL DEFAULT 'normal',
    status              VARCHAR(25) NOT NULL DEFAULT 'draft',
    confirmed_at        DATETIME(3),
    closed_at           DATETIME(3),
    close_reason        VARCHAR(255),
    notes               TEXT,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY sales_orders_number_uq (number),
    KEY sales_orders_open_idx (status, customer_id, delivery_date),
    KEY sales_orders_quotation_idx (quotation_id),
    KEY sales_orders_currency_idx (currency_id),
    KEY sales_orders_term_idx (payment_term_id),
    KEY sales_orders_billto_idx (billing_address_id),
    KEY sales_orders_shipto_idx (delivery_address_id),
    KEY sales_orders_merch_idx (merchandiser_id),
    KEY sales_orders_unit_idx (factory_unit_id),
    KEY sales_orders_creator_idx (created_by),
    CONSTRAINT sales_orders_quotation_fk FOREIGN KEY (quotation_id)        REFERENCES quotations(id),
    CONSTRAINT sales_orders_customer_fk  FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT sales_orders_currency_fk  FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT sales_orders_term_fk      FOREIGN KEY (payment_term_id)     REFERENCES payment_terms(id),
    CONSTRAINT sales_orders_billto_fk    FOREIGN KEY (billing_address_id)  REFERENCES customer_addresses(id),
    CONSTRAINT sales_orders_shipto_fk    FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT sales_orders_merch_fk     FOREIGN KEY (merchandiser_id)     REFERENCES employees(id),
    CONSTRAINT sales_orders_unit_fk      FOREIGN KEY (factory_unit_id)     REFERENCES factory_units(id),
    CONSTRAINT sales_orders_creator_fk   FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT sales_orders_priority_fk FOREIGN KEY (priority) REFERENCES order_priorities(code),
    CONSTRAINT sales_orders_status_chk   CHECK (status IN ('draft','credit_hold','confirmed','in_production','partially_delivered','delivered','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_order_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_order_id      BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    product_id          BIGINT UNSIGNED NOT NULL,
    product_spec_id     BIGINT UNSIGNED NOT NULL,
    artwork_version_id  BIGINT UNSIGNED,
    description         VARCHAR(255),
    ordered_qty         DECIMAL(18,6) NOT NULL,
    produced_qty        DECIMAL(18,6) NOT NULL DEFAULT 0,
    delivered_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    invoiced_qty        DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate_per_m          DECIMAL(18,4) NOT NULL,
    tooling_charge      DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_id              BIGINT UNSIGNED,
    line_total          DECIMAL(18,4) NOT NULL DEFAULT 0,
    over_tolerance_pct  DECIMAL(9,4) NOT NULL DEFAULT 5,
    under_tolerance_pct DECIMAL(9,4) NOT NULL DEFAULT 5,
    promised_date       DATE,
    status              VARCHAR(20) NOT NULL DEFAULT 'open',
    UNIQUE KEY sales_order_lines_uq (sales_order_id, line_no),
    KEY sales_order_lines_product_idx (product_id),
    KEY sales_order_lines_spec_idx (product_spec_id),
    KEY sales_order_lines_artwork_idx (artwork_version_id),
    KEY sales_order_lines_tax_idx (tax_id),
    CONSTRAINT sales_order_lines_order_fk   FOREIGN KEY (sales_order_id)     REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT sales_order_lines_product_fk FOREIGN KEY (product_id)         REFERENCES products(id),
    CONSTRAINT sales_order_lines_spec_fk    FOREIGN KEY (product_spec_id)    REFERENCES product_specs(id),
    CONSTRAINT sales_order_lines_artwork_fk FOREIGN KEY (artwork_version_id) REFERENCES artwork_versions(id),
    CONSTRAINT sales_order_lines_tax_fk     FOREIGN KEY (tax_id)             REFERENCES taxes(id),
    CONSTRAINT sales_order_lines_qty_chk    CHECK (ordered_qty > 0),
    CONSTRAINT sales_order_lines_rate_chk   CHECK (rate_per_m >= 0),
    CONSTRAINT sales_order_lines_status_chk CHECK (status IN ('open','planned','in_production','completed','short_closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE so_delivery_schedules (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_order_line_id BIGINT UNSIGNED NOT NULL,
    sequence_no         SMALLINT UNSIGNED NOT NULL,
    qty                 DECIMAL(18,6) NOT NULL,
    due_date            DATE NOT NULL,
    delivered_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    UNIQUE KEY so_delivery_schedules_uq (sales_order_line_id, sequence_no),
    CONSTRAINT so_delivery_schedules_line_fk FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id) ON DELETE CASCADE,
    CONSTRAINT so_delivery_schedules_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE so_amendments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    revision_no    SMALLINT UNSIGNED NOT NULL,
    changed_field  VARCHAR(80)  NOT NULL,
    old_value      VARCHAR(255),
    new_value      VARCHAR(255),
    reason         VARCHAR(500) NOT NULL,
    approved_by    BIGINT UNSIGNED,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by     BIGINT UNSIGNED,
    KEY so_amendments_order_idx (sales_order_id),
    KEY so_amendments_approver_idx (approved_by),
    KEY so_amendments_creator_idx (created_by),
    CONSTRAINT so_amendments_order_fk    FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT so_amendments_approver_fk FOREIGN KEY (approved_by)    REFERENCES users(id),
    CONSTRAINT so_amendments_creator_fk  FOREIGN KEY (created_by)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 5. SAMPLING
-- =====================================================================

CREATE TABLE sample_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    customer_id     BIGINT UNSIGNED NOT NULL,
    inquiry_id      BIGINT UNSIGNED,
    sales_order_id  BIGINT UNSIGNED,
    sample_type     VARCHAR(20) NOT NULL,
    requested_on    DATE NOT NULL DEFAULT (CURRENT_DATE),
    required_by     DATE,
    is_chargeable   BOOLEAN NOT NULL DEFAULT FALSE,
    charge_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'requested',
    merchandiser_id BIGINT UNSIGNED,
    notes           TEXT,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY sample_requests_number_uq (number),
    KEY sample_requests_customer_idx (customer_id, status),
    KEY sample_requests_inquiry_idx (inquiry_id),
    KEY sample_requests_order_idx (sales_order_id),
    KEY sample_requests_merch_idx (merchandiser_id),
    KEY sample_requests_creator_idx (created_by),
    CONSTRAINT sample_requests_customer_fk FOREIGN KEY (customer_id)     REFERENCES customers(id),
    CONSTRAINT sample_requests_inquiry_fk  FOREIGN KEY (inquiry_id)      REFERENCES inquiries(id),
    CONSTRAINT sample_requests_order_fk    FOREIGN KEY (sales_order_id)  REFERENCES sales_orders(id),
    CONSTRAINT sample_requests_merch_fk    FOREIGN KEY (merchandiser_id) REFERENCES employees(id),
    CONSTRAINT sample_requests_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT sample_requests_type_chk   CHECK (sample_type IN ('proto','approval','colour','size_set','pre_production','shipment','counter')),
    CONSTRAINT sample_requests_status_chk CHECK (status IN ('requested','in_development','in_production','ready','dispatched','approved','rejected','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sample_request_lines (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sample_request_id  BIGINT UNSIGNED NOT NULL,
    line_no            SMALLINT UNSIGNED NOT NULL,
    product_id         BIGINT UNSIGNED,
    product_spec_id    BIGINT UNSIGNED,
    artwork_version_id BIGINT UNSIGNED,
    description        VARCHAR(255) NOT NULL,
    qty                DECIMAL(18,6) NOT NULL,
    colourway          VARCHAR(80),
    status             VARCHAR(20) NOT NULL DEFAULT 'pending',
    UNIQUE KEY sample_request_lines_uq (sample_request_id, line_no),
    KEY sample_request_lines_product_idx (product_id),
    KEY sample_request_lines_spec_idx (product_spec_id),
    KEY sample_request_lines_artwork_idx (artwork_version_id),
    CONSTRAINT sample_request_lines_req_fk     FOREIGN KEY (sample_request_id)  REFERENCES sample_requests(id) ON DELETE CASCADE,
    CONSTRAINT sample_request_lines_product_fk FOREIGN KEY (product_id)         REFERENCES products(id),
    CONSTRAINT sample_request_lines_spec_fk    FOREIGN KEY (product_spec_id)    REFERENCES product_specs(id),
    CONSTRAINT sample_request_lines_artwork_fk FOREIGN KEY (artwork_version_id) REFERENCES artwork_versions(id),
    CONSTRAINT sample_request_lines_qty_chk    CHECK (qty > 0),
    CONSTRAINT sample_request_lines_status_chk CHECK (status IN ('pending','produced','dispatched','approved','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sample_dispatches (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sample_request_id BIGINT UNSIGNED NOT NULL,
    dispatched_on     DATE NOT NULL DEFAULT (CURRENT_DATE),
    courier_name      VARCHAR(80),
    tracking_no       VARCHAR(80),
    recipient         VARCHAR(150),
    delivered_on      DATE,
    remarks           VARCHAR(255),
    KEY sample_dispatches_req_idx (sample_request_id),
    CONSTRAINT sample_dispatches_req_fk FOREIGN KEY (sample_request_id) REFERENCES sample_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sample_approvals (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sample_request_line_id BIGINT UNSIGNED NOT NULL,
    decision               VARCHAR(30) NOT NULL,
    decided_on             DATE NOT NULL DEFAULT (CURRENT_DATE),
    customer_ref           VARCHAR(180),
    comments               TEXT,
    recorded_by            BIGINT UNSIGNED,
    created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY sample_approvals_line_idx (sample_request_line_id),
    KEY sample_approvals_user_idx (recorded_by),
    CONSTRAINT sample_approvals_line_fk FOREIGN KEY (sample_request_line_id) REFERENCES sample_request_lines(id) ON DELETE CASCADE,
    CONSTRAINT sample_approvals_user_fk FOREIGN KEY (recorded_by)            REFERENCES users(id),
    CONSTRAINT sample_approvals_decision_chk CHECK (decision IN ('approved','approved_with_comments','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 6. PROCUREMENT
-- =====================================================================

CREATE TABLE purchase_requisitions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    department_id   BIGINT UNSIGNED,
    requested_on    DATE NOT NULL DEFAULT (CURRENT_DATE),
    required_by     DATE,
    origin          VARCHAR(20) NOT NULL DEFAULT 'manual',
    status          VARCHAR(25) NOT NULL DEFAULT 'draft',
    approved_by     BIGINT UNSIGNED,
    approved_at     DATETIME(3),
    remarks         VARCHAR(500),
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY purchase_requisitions_number_uq (number),
    KEY purchase_requisitions_status_idx (status, required_by),
    KEY purchase_requisitions_unit_idx (factory_unit_id),
    KEY purchase_requisitions_dept_idx (department_id),
    KEY purchase_requisitions_approver_idx (approved_by),
    KEY purchase_requisitions_creator_idx (created_by),
    CONSTRAINT purchase_requisitions_unit_fk     FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT purchase_requisitions_dept_fk     FOREIGN KEY (department_id)   REFERENCES departments(id),
    CONSTRAINT purchase_requisitions_approver_fk FOREIGN KEY (approved_by)     REFERENCES users(id),
    CONSTRAINT purchase_requisitions_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT purchase_requisitions_origin_chk CHECK (origin IN ('manual','mrp','reorder_level')),
    CONSTRAINT purchase_requisitions_status_chk CHECK (status IN ('draft','submitted','approved','partially_ordered','ordered','rejected','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_requisition_lines (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pr_id       BIGINT UNSIGNED NOT NULL,
    line_no     SMALLINT UNSIGNED NOT NULL,
    item_id     BIGINT UNSIGNED NOT NULL,
    uom_id      BIGINT UNSIGNED NOT NULL,
    qty         DECIMAL(18,6) NOT NULL,
    ordered_qty DECIMAL(18,6) NOT NULL DEFAULT 0,
    required_by DATE,
    job_card_id BIGINT UNSIGNED,             -- FK added in §9 (circular)
    remarks     VARCHAR(255),
    UNIQUE KEY purchase_requisition_lines_uq (pr_id, line_no),
    KEY purchase_requisition_lines_item_idx (item_id),
    KEY purchase_requisition_lines_uom_idx (uom_id),
    KEY purchase_requisition_lines_job_idx (job_card_id),
    CONSTRAINT purchase_requisition_lines_pr_fk   FOREIGN KEY (pr_id)   REFERENCES purchase_requisitions(id) ON DELETE CASCADE,
    CONSTRAINT purchase_requisition_lines_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT purchase_requisition_lines_uom_fk  FOREIGN KEY (uom_id)  REFERENCES uoms(id),
    CONSTRAINT purchase_requisition_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_rfqs (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number     VARCHAR(30),
    pr_id      BIGINT UNSIGNED,
    issued_on  DATE NOT NULL DEFAULT (CURRENT_DATE),
    respond_by DATE,
    status     VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY supplier_rfqs_number_uq (number),
    KEY supplier_rfqs_pr_idx (pr_id),
    KEY supplier_rfqs_creator_idx (created_by),
    CONSTRAINT supplier_rfqs_pr_fk      FOREIGN KEY (pr_id)      REFERENCES purchase_requisitions(id),
    CONSTRAINT supplier_rfqs_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT supplier_rfqs_status_chk CHECK (status IN ('draft','issued','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_rfq_lines (
    id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rfq_id  BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    qty     DECIMAL(18,6) NOT NULL,
    uom_id  BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY supplier_rfq_lines_uq (rfq_id, line_no),
    KEY supplier_rfq_lines_item_idx (item_id),
    KEY supplier_rfq_lines_uom_idx (uom_id),
    CONSTRAINT supplier_rfq_lines_rfq_fk  FOREIGN KEY (rfq_id)  REFERENCES supplier_rfqs(id) ON DELETE CASCADE,
    CONSTRAINT supplier_rfq_lines_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT supplier_rfq_lines_uom_fk  FOREIGN KEY (uom_id)  REFERENCES uoms(id),
    CONSTRAINT supplier_rfq_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_quotations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rfq_id         BIGINT UNSIGNED,
    supplier_id    BIGINT UNSIGNED NOT NULL,
    quoted_on      DATE NOT NULL DEFAULT (CURRENT_DATE),
    valid_until    DATE,
    currency_id    BIGINT UNSIGNED NOT NULL,
    total          DECIMAL(18,4) NOT NULL DEFAULT 0,
    lead_time_days SMALLINT UNSIGNED,
    is_selected    BOOLEAN NOT NULL DEFAULT FALSE,
    remarks        VARCHAR(500),
    KEY supplier_quotations_rfq_idx (rfq_id),
    KEY supplier_quotations_supplier_idx (supplier_id),
    KEY supplier_quotations_currency_idx (currency_id),
    CONSTRAINT supplier_quotations_rfq_fk      FOREIGN KEY (rfq_id)      REFERENCES supplier_rfqs(id),
    CONSTRAINT supplier_quotations_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT supplier_quotations_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_quotation_lines (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_quotation_id BIGINT UNSIGNED NOT NULL,
    line_no               SMALLINT UNSIGNED NOT NULL,
    item_id               BIGINT UNSIGNED NOT NULL,
    qty                   DECIMAL(18,6) NOT NULL,
    uom_id                BIGINT UNSIGNED NOT NULL,
    rate                  DECIMAL(18,4) NOT NULL,
    amount                DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY supplier_quotation_lines_uq (supplier_quotation_id, line_no),
    KEY supplier_quotation_lines_item_idx (item_id),
    KEY supplier_quotation_lines_uom_idx (uom_id),
    CONSTRAINT supplier_quotation_lines_sq_fk   FOREIGN KEY (supplier_quotation_id) REFERENCES supplier_quotations(id) ON DELETE CASCADE,
    CONSTRAINT supplier_quotation_lines_item_fk FOREIGN KEY (item_id)               REFERENCES items(id),
    CONSTRAINT supplier_quotation_lines_uom_fk  FOREIGN KEY (uom_id)                REFERENCES uoms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_orders (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    revision_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    supplier_id     BIGINT UNSIGNED NOT NULL,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    order_date      DATE NOT NULL DEFAULT (CURRENT_DATE),
    expected_date   DATE,
    currency_id     BIGINT UNSIGNED NOT NULL,
    exchange_rate   DECIMAL(18,8) NOT NULL DEFAULT 1,
    payment_term_id BIGINT UNSIGNED,
    incoterm        VARCHAR(20),
    subtotal        DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(18,4) NOT NULL DEFAULT 0,
    freight_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    total           DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(25) NOT NULL DEFAULT 'draft',
    approved_by     BIGINT UNSIGNED,
    approved_at     DATETIME(3),
    remarks         VARCHAR(500),
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY purchase_orders_number_uq (number),
    KEY purchase_orders_open_idx (status, supplier_id, expected_date),
    KEY purchase_orders_unit_idx (factory_unit_id),
    KEY purchase_orders_currency_idx (currency_id),
    KEY purchase_orders_term_idx (payment_term_id),
    KEY purchase_orders_approver_idx (approved_by),
    KEY purchase_orders_creator_idx (created_by),
    CONSTRAINT purchase_orders_supplier_fk FOREIGN KEY (supplier_id)     REFERENCES suppliers(id),
    CONSTRAINT purchase_orders_unit_fk     FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT purchase_orders_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT purchase_orders_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id),
    CONSTRAINT purchase_orders_approver_fk FOREIGN KEY (approved_by)     REFERENCES users(id),
    CONSTRAINT purchase_orders_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT purchase_orders_status_chk CHECK (status IN ('draft','pending_approval','approved','sent','partially_received','received','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_order_lines (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    po_id         BIGINT UNSIGNED NOT NULL,
    line_no       SMALLINT UNSIGNED NOT NULL,
    item_id       BIGINT UNSIGNED NOT NULL,
    pr_line_id    BIGINT UNSIGNED,
    description   VARCHAR(255),
    qty           DECIMAL(18,6) NOT NULL,
    uom_id        BIGINT UNSIGNED NOT NULL,
    rate          DECIMAL(18,4) NOT NULL,
    tax_id        BIGINT UNSIGNED,
    amount        DECIMAL(18,4) NOT NULL DEFAULT 0,
    received_qty  DECIMAL(18,6) NOT NULL DEFAULT 0,
    billed_qty    DECIMAL(18,6) NOT NULL DEFAULT 0,
    expected_date DATE,
    cert_claim    VARCHAR(20),
    UNIQUE KEY purchase_order_lines_uq (po_id, line_no),
    KEY purchase_order_lines_item_idx (item_id),
    KEY purchase_order_lines_prline_idx (pr_line_id),
    KEY purchase_order_lines_uom_idx (uom_id),
    KEY purchase_order_lines_tax_idx (tax_id),
    CONSTRAINT purchase_order_lines_po_fk     FOREIGN KEY (po_id)      REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT purchase_order_lines_item_fk   FOREIGN KEY (item_id)    REFERENCES items(id),
    CONSTRAINT purchase_order_lines_prline_fk FOREIGN KEY (pr_line_id) REFERENCES purchase_requisition_lines(id),
    CONSTRAINT purchase_order_lines_uom_fk    FOREIGN KEY (uom_id)     REFERENCES uoms(id),
    CONSTRAINT purchase_order_lines_tax_fk    FOREIGN KEY (tax_id)     REFERENCES taxes(id),
    CONSTRAINT purchase_order_lines_qty_chk  CHECK (qty > 0),
    CONSTRAINT purchase_order_lines_rate_chk CHECK (rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE grns (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    po_id           BIGINT UNSIGNED,
    supplier_id     BIGINT UNSIGNED NOT NULL,
    warehouse_id    BIGINT UNSIGNED NOT NULL,
    received_on     DATE NOT NULL DEFAULT (CURRENT_DATE),
    challan_no      VARCHAR(60),
    invoice_no      VARCHAR(60),
    lc_no           VARCHAR(60),
    bill_of_entry   VARCHAR(60),
    freight_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    duty_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    clearing_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks         VARCHAR(500),
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY grns_number_uq (number),
    KEY grns_po_idx (po_id),
    KEY grns_supplier_idx (supplier_id, received_on),
    KEY grns_warehouse_idx (warehouse_id),
    KEY grns_creator_idx (created_by),
    CONSTRAINT grns_po_fk        FOREIGN KEY (po_id)        REFERENCES purchase_orders(id),
    CONSTRAINT grns_supplier_fk  FOREIGN KEY (supplier_id)  REFERENCES suppliers(id),
    CONSTRAINT grns_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT grns_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT grns_status_chk CHECK (status IN ('draft','pending_qc','accepted','partially_accepted','rejected','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE grn_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    grn_id            BIGINT UNSIGNED NOT NULL,
    line_no           SMALLINT UNSIGNED NOT NULL,
    po_line_id        BIGINT UNSIGNED,
    item_id           BIGINT UNSIGNED NOT NULL,
    uom_id            BIGINT UNSIGNED NOT NULL,
    received_qty      DECIMAL(18,6) NOT NULL,
    accepted_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    rejected_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate              DECIMAL(18,4) NOT NULL DEFAULT 0,
    landed_rate       DECIMAL(18,4) NOT NULL DEFAULT 0,
    supplier_batch_no VARCHAR(60),
    shade_code        VARCHAR(40),
    manufactured_on   DATE,
    expiry_date       DATE,
    cert_scheme       VARCHAR(20),
    cert_claim_pct    DECIMAL(9,4) NOT NULL DEFAULT 0,
    cert_document_no  VARCHAR(80),
    UNIQUE KEY grn_lines_uq (grn_id, line_no),
    KEY grn_lines_poline_idx (po_line_id),
    KEY grn_lines_item_idx (item_id),
    KEY grn_lines_uom_idx (uom_id),
    KEY grn_lines_cert_idx (cert_scheme),
    CONSTRAINT grn_lines_grn_fk    FOREIGN KEY (grn_id)     REFERENCES grns(id) ON DELETE CASCADE,
    CONSTRAINT grn_lines_poline_fk FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines(id),
    CONSTRAINT grn_lines_item_fk   FOREIGN KEY (item_id)    REFERENCES items(id),
    CONSTRAINT grn_lines_uom_fk    FOREIGN KEY (uom_id)     REFERENCES uoms(id),
    CONSTRAINT grn_lines_qty_chk  CHECK (received_qty > 0),
    CONSTRAINT grn_lines_cert_chk CHECK (cert_claim_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_returns (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number      VARCHAR(30),
    grn_id      BIGINT UNSIGNED,
    supplier_id BIGINT UNSIGNED NOT NULL,
    returned_on DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason      VARCHAR(500) NOT NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'draft',
    created_by  BIGINT UNSIGNED,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY purchase_returns_number_uq (number),
    KEY purchase_returns_grn_idx (grn_id),
    KEY purchase_returns_supplier_idx (supplier_id),
    KEY purchase_returns_creator_idx (created_by),
    CONSTRAINT purchase_returns_grn_fk      FOREIGN KEY (grn_id)      REFERENCES grns(id),
    CONSTRAINT purchase_returns_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT purchase_returns_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT purchase_returns_status_chk CHECK (status IN ('draft','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_return_lines (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id BIGINT UNSIGNED NOT NULL,
    line_no            SMALLINT UNSIGNED NOT NULL,
    item_id            BIGINT UNSIGNED NOT NULL,
    lot_id             BIGINT UNSIGNED,          -- FK added in §7 (circular)
    qty                DECIMAL(18,6) NOT NULL,
    rate               DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY purchase_return_lines_uq (purchase_return_id, line_no),
    KEY purchase_return_lines_item_idx (item_id),
    KEY purchase_return_lines_lot_idx (lot_id),
    CONSTRAINT purchase_return_lines_ret_fk  FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
    CONSTRAINT purchase_return_lines_item_fk FOREIGN KEY (item_id)            REFERENCES items(id),
    CONSTRAINT purchase_return_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_bills (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number        VARCHAR(30),
    supplier_id   BIGINT UNSIGNED NOT NULL,
    po_id         BIGINT UNSIGNED,
    grn_id        BIGINT UNSIGNED,
    bill_no       VARCHAR(60) NOT NULL,
    bill_date     DATE NOT NULL,
    due_date      DATE,
    currency_id   BIGINT UNSIGNED NOT NULL,
    exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
    subtotal      DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount    DECIMAL(18,4) NOT NULL DEFAULT 0,
    total         DECIMAL(18,4) NOT NULL DEFAULT 0,
    paid_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    status        VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED,
    UNIQUE KEY supplier_bills_number_uq (number),
    KEY supplier_bills_supplier_idx (supplier_id, status, due_date),
    KEY supplier_bills_po_idx (po_id),
    KEY supplier_bills_grn_idx (grn_id),
    KEY supplier_bills_currency_idx (currency_id),
    KEY supplier_bills_creator_idx (created_by),
    CONSTRAINT supplier_bills_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT supplier_bills_po_fk       FOREIGN KEY (po_id)       REFERENCES purchase_orders(id),
    CONSTRAINT supplier_bills_grn_fk      FOREIGN KEY (grn_id)      REFERENCES grns(id),
    CONSTRAINT supplier_bills_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT supplier_bills_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT supplier_bills_status_chk CHECK (status IN ('draft','approved','partially_paid','paid','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_bill_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_bill_id BIGINT UNSIGNED NOT NULL,
    line_no          SMALLINT UNSIGNED NOT NULL,
    item_id          BIGINT UNSIGNED,
    description      VARCHAR(255),
    qty              DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate             DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_id           BIGINT UNSIGNED,
    amount           DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY supplier_bill_lines_uq (supplier_bill_id, line_no),
    KEY supplier_bill_lines_item_idx (item_id),
    KEY supplier_bill_lines_tax_idx (tax_id),
    CONSTRAINT supplier_bill_lines_bill_fk FOREIGN KEY (supplier_bill_id) REFERENCES supplier_bills(id) ON DELETE CASCADE,
    CONSTRAINT supplier_bill_lines_item_fk FOREIGN KEY (item_id)          REFERENCES items(id),
    CONSTRAINT supplier_bill_lines_tax_fk  FOREIGN KEY (tax_id)           REFERENCES taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 7. INVENTORY
-- =====================================================================

CREATE TABLE stock_lots (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_no            VARCHAR(40) NOT NULL,
    item_id           BIGINT UNSIGNED,
    product_id        BIGINT UNSIGNED,
    kind              VARCHAR(20) NOT NULL,
    warehouse_id      BIGINT UNSIGNED NOT NULL,
    bin_id            BIGINT UNSIGNED,
    uom_id            BIGINT UNSIGNED NOT NULL,
    received_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    balance_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,   -- derived cache, I3
    unit_cost         DECIMAL(18,4) NOT NULL DEFAULT 0,
    grn_line_id       BIGINT UNSIGNED,
    job_card_id       BIGINT UNSIGNED,                    -- FK added in §9 (circular)
    parent_lot_id     BIGINT UNSIGNED,
    supplier_batch_no VARCHAR(60),
    shade_code        VARCHAR(40),
    roll_length_m     DECIMAL(18,6),                      -- lot-level UoM override, BR-3
    received_on       DATE NOT NULL DEFAULT (CURRENT_DATE),
    expiry_date       DATE,
    cert_scheme       VARCHAR(20),                        -- claim carried by the lot, I5
    cert_claim_pct    DECIMAL(9,4) NOT NULL DEFAULT 0,
    cert_document_no  VARCHAR(80),
    status            VARCHAR(20) NOT NULL DEFAULT 'available',
    barcode           VARCHAR(64),
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY stock_lots_lot_no_uq (lot_no),
    UNIQUE KEY stock_lots_barcode_uq (barcode),
    KEY stock_lots_item_wh_idx (item_id, warehouse_id, status),
    KEY stock_lots_product_idx (product_id, status),
    KEY stock_lots_shade_idx (item_id, shade_code, status),
    KEY stock_lots_bin_idx (bin_id),
    KEY stock_lots_uom_idx (uom_id),
    KEY stock_lots_grnline_idx (grn_line_id),
    KEY stock_lots_parent_idx (parent_lot_id),
    KEY stock_lots_job_idx (job_card_id),
    KEY stock_lots_expiry_idx (expiry_date),
    CONSTRAINT stock_lots_item_fk      FOREIGN KEY (item_id)       REFERENCES items(id),
    CONSTRAINT stock_lots_product_fk   FOREIGN KEY (product_id)    REFERENCES products(id),
    CONSTRAINT stock_lots_warehouse_fk FOREIGN KEY (warehouse_id)  REFERENCES warehouses(id),
    CONSTRAINT stock_lots_bin_fk       FOREIGN KEY (bin_id)        REFERENCES bins(id),
    CONSTRAINT stock_lots_uom_fk       FOREIGN KEY (uom_id)        REFERENCES uoms(id),
    CONSTRAINT stock_lots_grnline_fk   FOREIGN KEY (grn_line_id)   REFERENCES grn_lines(id),
    CONSTRAINT stock_lots_parent_fk    FOREIGN KEY (parent_lot_id) REFERENCES stock_lots(id),
    CONSTRAINT stock_lots_kind_chk    CHECK (kind IN ('raw_material','wip','finished_goods','sample','scrap','second_quality')),
    CONSTRAINT stock_lots_status_chk  CHECK (status IN ('quarantine','available','reserved','consumed','blocked','expired','scrapped')),
    CONSTRAINT stock_lots_owner_chk   CHECK (item_id IS NOT NULL OR product_id IS NOT NULL),
    CONSTRAINT stock_lots_balance_chk CHECK (balance_qty >= 0),          -- BR-38 / I4
    CONSTRAINT stock_lots_cert_chk    CHECK (cert_claim_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE purchase_return_lines
    ADD CONSTRAINT purchase_return_lines_lot_fk FOREIGN KEY (lot_id) REFERENCES stock_lots(id);

CREATE TABLE stock_ledger (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_id        BIGINT UNSIGNED NOT NULL,
    item_id       BIGINT UNSIGNED,
    product_id    BIGINT UNSIGNED,
    warehouse_id  BIGINT UNSIGNED NOT NULL,
    bin_id        BIGINT UNSIGNED,
    movement_type VARCHAR(30) NOT NULL,
    qty           DECIMAL(18,6) NOT NULL,          -- signed
    uom_id        BIGINT UNSIGNED NOT NULL,
    unit_cost     DECIMAL(18,4) NOT NULL DEFAULT 0,
    value         DECIMAL(18,4) NOT NULL DEFAULT 0,
    source_type   VARCHAR(120) NOT NULL,
    source_id     BIGINT UNSIGNED NOT NULL,
    occurred_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED,
    remarks       VARCHAR(255),
    KEY stock_ledger_lot_idx (lot_id, occurred_at),
    KEY stock_ledger_item_idx (item_id, warehouse_id, occurred_at),
    KEY stock_ledger_product_idx (product_id, occurred_at),
    KEY stock_ledger_source_idx (source_type, source_id),
    KEY stock_ledger_warehouse_idx (warehouse_id),
    KEY stock_ledger_bin_idx (bin_id),
    KEY stock_ledger_uom_idx (uom_id),
    KEY stock_ledger_creator_idx (created_by),
    CONSTRAINT stock_ledger_lot_fk       FOREIGN KEY (lot_id)       REFERENCES stock_lots(id),
    CONSTRAINT stock_ledger_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT stock_ledger_product_fk   FOREIGN KEY (product_id)   REFERENCES products(id),
    CONSTRAINT stock_ledger_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_ledger_bin_fk       FOREIGN KEY (bin_id)       REFERENCES bins(id),
    CONSTRAINT stock_ledger_uom_fk       FOREIGN KEY (uom_id)       REFERENCES uoms(id),
    CONSTRAINT stock_ledger_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT stock_ledger_qty_chk  CHECK (qty <> 0),
    CONSTRAINT stock_ledger_type_chk CHECK (movement_type IN (
        'grn_receipt','purchase_return','issue_to_job','return_from_job',
        'production_output','wip_transfer','transfer_in','transfer_out',
        'adjustment_in','adjustment_out','waste','scrap','sample_issue',
        'fg_receipt','dispatch','sales_return','count_variance'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_reservations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_id       BIGINT UNSIGNED,
    item_id      BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    job_card_id  BIGINT UNSIGNED,                 -- FK added in §9 (circular)
    qty          DECIMAL(18,6) NOT NULL,
    reserved_on  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    released_on  DATETIME(3),
    status       VARCHAR(20) NOT NULL DEFAULT 'active',
    KEY stock_reservations_active_idx (item_id, warehouse_id, status),
    KEY stock_reservations_lot_idx (lot_id),
    KEY stock_reservations_job_idx (job_card_id),
    CONSTRAINT stock_reservations_lot_fk       FOREIGN KEY (lot_id)       REFERENCES stock_lots(id),
    CONSTRAINT stock_reservations_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT stock_reservations_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_reservations_qty_chk    CHECK (qty > 0),
    CONSTRAINT stock_reservations_status_chk CHECK (status IN ('active','consumed','released'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_transfers (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number            VARCHAR(30),
    from_warehouse_id BIGINT UNSIGNED NOT NULL,
    to_warehouse_id   BIGINT UNSIGNED NOT NULL,
    transfer_date     DATE NOT NULL DEFAULT (CURRENT_DATE),
    status            VARCHAR(20) NOT NULL DEFAULT 'draft',
    remarks           VARCHAR(255),
    created_by        BIGINT UNSIGNED,
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY stock_transfers_number_uq (number),
    KEY stock_transfers_from_idx (from_warehouse_id),
    KEY stock_transfers_to_idx (to_warehouse_id),
    KEY stock_transfers_creator_idx (created_by),
    CONSTRAINT stock_transfers_from_fk    FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_transfers_to_fk      FOREIGN KEY (to_warehouse_id)   REFERENCES warehouses(id),
    CONSTRAINT stock_transfers_creator_fk FOREIGN KEY (created_by)        REFERENCES users(id),
    CONSTRAINT stock_transfers_status_chk CHECK (status IN ('draft','in_transit','received','cancelled')),
    CONSTRAINT stock_transfers_diff_chk   CHECK (from_warehouse_id <> to_warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_transfer_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stock_transfer_id BIGINT UNSIGNED NOT NULL,
    line_no           SMALLINT UNSIGNED NOT NULL,
    lot_id            BIGINT UNSIGNED NOT NULL,
    qty               DECIMAL(18,6) NOT NULL,
    received_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    UNIQUE KEY stock_transfer_lines_uq (stock_transfer_id, line_no),
    KEY stock_transfer_lines_lot_idx (lot_id),
    CONSTRAINT stock_transfer_lines_transfer_fk FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT stock_transfer_lines_lot_fk      FOREIGN KEY (lot_id)            REFERENCES stock_lots(id),
    CONSTRAINT stock_transfer_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_adjustments (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(30),
    warehouse_id BIGINT UNSIGNED NOT NULL,
    adjusted_on  DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason       VARCHAR(500) NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'draft',
    approved_by  BIGINT UNSIGNED,
    created_by   BIGINT UNSIGNED,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY stock_adjustments_number_uq (number),
    KEY stock_adjustments_warehouse_idx (warehouse_id),
    KEY stock_adjustments_approver_idx (approved_by),
    KEY stock_adjustments_creator_idx (created_by),
    CONSTRAINT stock_adjustments_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_adjustments_approver_fk  FOREIGN KEY (approved_by)  REFERENCES users(id),
    CONSTRAINT stock_adjustments_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT stock_adjustments_status_chk CHECK (status IN ('draft','pending_approval','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_adjustment_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stock_adjustment_id BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    lot_id              BIGINT UNSIGNED NOT NULL,
    qty_delta           DECIMAL(18,6) NOT NULL,
    remarks             VARCHAR(255),
    UNIQUE KEY stock_adjustment_lines_uq (stock_adjustment_id, line_no),
    KEY stock_adjustment_lines_lot_idx (lot_id),
    CONSTRAINT stock_adjustment_lines_adj_fk FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    CONSTRAINT stock_adjustment_lines_lot_fk FOREIGN KEY (lot_id)              REFERENCES stock_lots(id),
    CONSTRAINT stock_adjustment_lines_qty_chk CHECK (qty_delta <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE physical_counts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(30),
    warehouse_id BIGINT UNSIGNED NOT NULL,
    counted_on   DATE NOT NULL DEFAULT (CURRENT_DATE),
    status       VARCHAR(20) NOT NULL DEFAULT 'open',
    created_by   BIGINT UNSIGNED,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY physical_counts_number_uq (number),
    KEY physical_counts_warehouse_idx (warehouse_id),
    KEY physical_counts_creator_idx (created_by),
    CONSTRAINT physical_counts_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT physical_counts_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT physical_counts_status_chk CHECK (status IN ('open','counting','reconciled','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE physical_count_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    physical_count_id BIGINT UNSIGNED NOT NULL,
    lot_id            BIGINT UNSIGNED NOT NULL,
    system_qty        DECIMAL(18,6) NOT NULL DEFAULT 0,
    counted_qty       DECIMAL(18,6),
    variance_qty      DECIMAL(18,6) GENERATED ALWAYS AS (IFNULL(counted_qty, 0) - system_qty) STORED,
    counted_by        BIGINT UNSIGNED,
    remarks           VARCHAR(255),
    UNIQUE KEY physical_count_lines_uq (physical_count_id, lot_id),
    KEY physical_count_lines_lot_idx (lot_id),
    KEY physical_count_lines_user_idx (counted_by),
    CONSTRAINT physical_count_lines_count_fk FOREIGN KEY (physical_count_id) REFERENCES physical_counts(id) ON DELETE CASCADE,
    CONSTRAINT physical_count_lines_lot_fk   FOREIGN KEY (lot_id)            REFERENCES stock_lots(id),
    CONSTRAINT physical_count_lines_user_fk  FOREIGN KEY (counted_by)        REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 8. PLANNING & MRP
-- =====================================================================

CREATE TABLE capacity_calendars (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id           BIGINT UNSIGNED,
    machine_group_id     BIGINT UNSIGNED,
    calendar_date        DATE NOT NULL,
    shift_id             BIGINT UNSIGNED,
    available_minutes    DECIMAL(9,2) NOT NULL DEFAULT 0,
    planned_downtime_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    is_holiday           BOOLEAN NOT NULL DEFAULT FALSE,
    remarks              VARCHAR(255),
    KEY capacity_calendars_date_idx (calendar_date, machine_id),
    KEY capacity_calendars_group_idx (machine_group_id),
    KEY capacity_calendars_shift_idx (shift_id),
    KEY capacity_calendars_machine_idx (machine_id),
    CONSTRAINT capacity_calendars_machine_fk FOREIGN KEY (machine_id)       REFERENCES machines(id),
    CONSTRAINT capacity_calendars_group_fk   FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT capacity_calendars_shift_fk   FOREIGN KEY (shift_id)         REFERENCES shifts(id),
    CONSTRAINT capacity_calendars_minutes_chk CHECK (available_minutes >= 0),
    CONSTRAINT capacity_calendars_target_chk  CHECK (machine_id IS NOT NULL OR machine_group_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE production_plans (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    plan_from       DATE NOT NULL,
    plan_to         DATE NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by      BIGINT UNSIGNED,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY production_plans_number_uq (number),
    KEY production_plans_unit_idx (factory_unit_id, plan_from),
    KEY production_plans_creator_idx (created_by),
    CONSTRAINT production_plans_unit_fk    FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT production_plans_creator_fk FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT production_plans_status_chk CHECK (status IN ('draft','frozen','released','closed')),
    CONSTRAINT production_plans_range_chk  CHECK (plan_to >= plan_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE production_plan_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    production_plan_id  BIGINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED NOT NULL,
    planned_qty         DECIMAL(18,6) NOT NULL,
    planned_start       DATE,
    planned_finish      DATE,
    machine_group_id    BIGINT UNSIGNED,
    priority            SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    status              VARCHAR(20) NOT NULL DEFAULT 'planned',
    KEY production_plan_lines_plan_idx (production_plan_id),
    KEY production_plan_lines_soline_idx (sales_order_line_id),
    KEY production_plan_lines_product_idx (product_id),
    KEY production_plan_lines_group_idx (machine_group_id),
    CONSTRAINT production_plan_lines_plan_fk    FOREIGN KEY (production_plan_id)  REFERENCES production_plans(id) ON DELETE CASCADE,
    CONSTRAINT production_plan_lines_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT production_plan_lines_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT production_plan_lines_group_fk   FOREIGN KEY (machine_group_id)    REFERENCES machine_groups(id),
    CONSTRAINT production_plan_lines_qty_chk    CHECK (planned_qty > 0),
    CONSTRAINT production_plan_lines_status_chk CHECK (status IN ('planned','released','in_production','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mrp_runs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    horizon_from    DATE NOT NULL,
    horizon_to      DATE NOT NULL,
    run_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    run_by          BIGINT UNSIGNED,
    status          VARCHAR(20) NOT NULL DEFAULT 'running',
    shortage_count  INT UNSIGNED NOT NULL DEFAULT 0,
    notes           VARCHAR(500),
    KEY mrp_runs_unit_idx (factory_unit_id, run_at),
    KEY mrp_runs_user_idx (run_by),
    CONSTRAINT mrp_runs_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT mrp_runs_user_fk FOREIGN KEY (run_by)          REFERENCES users(id),
    CONSTRAINT mrp_runs_status_chk CHECK (status IN ('running','completed','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE material_requirements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mrp_run_id       BIGINT UNSIGNED NOT NULL,
    item_id          BIGINT UNSIGNED NOT NULL,
    warehouse_id     BIGINT UNSIGNED,
    need_date        DATE NOT NULL,
    gross_req_qty    DECIMAL(18,6) NOT NULL DEFAULT 0,
    on_hand_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    reserved_qty     DECIMAL(18,6) NOT NULL DEFAULT 0,
    on_order_qty     DECIMAL(18,6) NOT NULL DEFAULT 0,
    net_req_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    suggested_po_qty DECIMAL(18,6) NOT NULL DEFAULT 0,
    po_place_by      DATE,
    is_shortage      BOOLEAN NOT NULL DEFAULT FALSE,
    pr_line_id       BIGINT UNSIGNED,
    KEY material_requirements_shortage_idx (mrp_run_id, is_shortage, item_id),
    KEY material_requirements_item_idx (item_id, need_date),
    KEY material_requirements_warehouse_idx (warehouse_id),
    KEY material_requirements_prline_idx (pr_line_id),
    CONSTRAINT material_requirements_run_fk       FOREIGN KEY (mrp_run_id)   REFERENCES mrp_runs(id) ON DELETE CASCADE,
    CONSTRAINT material_requirements_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT material_requirements_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT material_requirements_prline_fk    FOREIGN KEY (pr_line_id)   REFERENCES purchase_requisition_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 9. MANUFACTURING
-- =====================================================================

CREATE TABLE job_cards (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number                  VARCHAR(30),
    factory_unit_id         BIGINT UNSIGNED NOT NULL,
    sales_order_line_id     BIGINT UNSIGNED,
    sample_request_line_id  BIGINT UNSIGNED,
    production_plan_line_id BIGINT UNSIGNED,
    product_id              BIGINT UNSIGNED NOT NULL,
    product_spec_id         BIGINT UNSIGNED NOT NULL,
    artwork_version_id      BIGINT UNSIGNED NOT NULL,   -- Gate 1 / J1
    bom_id                  BIGINT UNSIGNED,
    routing_id              BIGINT UNSIGNED NOT NULL,
    colourway               VARCHAR(80),
    planned_qty             DECIMAL(18,6) NOT NULL,
    produced_qty            DECIMAL(18,6) NOT NULL DEFAULT 0,
    good_qty                DECIMAL(18,6) NOT NULL DEFAULT 0,
    waste_qty               DECIMAL(18,6) NOT NULL DEFAULT 0,
    overrun_tolerance_pct   DECIMAL(9,4) NOT NULL DEFAULT 3,
    planned_start           DATETIME(3),
    planned_finish          DATETIME(3),
    actual_start            DATETIME(3),
    actual_finish           DATETIME(3),
    due_date                DATE,
    priority                SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    -- consumption plan snapshot (BR-4 … BR-13)
    gross_metres            DECIMAL(18,6),
    ends                    SMALLINT UNSIGNED,
    labels_per_metre        DECIMAL(18,6),
    status                  VARCHAR(25) NOT NULL DEFAULT 'draft',
    hold_reason             VARCHAR(500),
    material_waiver_reason  VARCHAR(500),
    created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by              BIGINT UNSIGNED,
    closed_at               DATETIME(3),
    UNIQUE KEY job_cards_number_uq (number),
    KEY job_cards_open_idx (status, factory_unit_id, due_date),
    KEY job_cards_product_idx (product_id),
    KEY job_cards_soline_idx (sales_order_line_id),
    KEY job_cards_sampleline_idx (sample_request_line_id),
    KEY job_cards_planline_idx (production_plan_line_id),
    KEY job_cards_spec_idx (product_spec_id),
    KEY job_cards_artwork_idx (artwork_version_id),
    KEY job_cards_bom_idx (bom_id),
    KEY job_cards_routing_idx (routing_id),
    KEY job_cards_creator_idx (created_by),
    CONSTRAINT job_cards_unit_fk       FOREIGN KEY (factory_unit_id)         REFERENCES factory_units(id),
    CONSTRAINT job_cards_soline_fk     FOREIGN KEY (sales_order_line_id)     REFERENCES sales_order_lines(id),
    CONSTRAINT job_cards_sampleline_fk FOREIGN KEY (sample_request_line_id)  REFERENCES sample_request_lines(id),
    CONSTRAINT job_cards_planline_fk   FOREIGN KEY (production_plan_line_id) REFERENCES production_plan_lines(id),
    CONSTRAINT job_cards_product_fk    FOREIGN KEY (product_id)              REFERENCES products(id),
    CONSTRAINT job_cards_spec_fk       FOREIGN KEY (product_spec_id)         REFERENCES product_specs(id),
    CONSTRAINT job_cards_artwork_fk    FOREIGN KEY (artwork_version_id)      REFERENCES artwork_versions(id),
    CONSTRAINT job_cards_bom_fk        FOREIGN KEY (bom_id)                  REFERENCES boms(id),
    CONSTRAINT job_cards_routing_fk    FOREIGN KEY (routing_id)              REFERENCES routings(id),
    CONSTRAINT job_cards_creator_fk    FOREIGN KEY (created_by)              REFERENCES users(id),
    CONSTRAINT job_cards_qty_chk    CHECK (planned_qty > 0),
    CONSTRAINT job_cards_status_chk CHECK (status IN ('draft','planned','material_pending','released','in_production','on_hold','qc_pending','completed','closed','cancelled')),
    CONSTRAINT job_cards_source_chk CHECK (sales_order_line_id IS NOT NULL OR sample_request_line_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- circular references resolved now that job_cards exists
ALTER TABLE purchase_requisition_lines
    ADD CONSTRAINT purchase_requisition_lines_job_fk FOREIGN KEY (job_card_id) REFERENCES job_cards(id);
ALTER TABLE stock_lots
    ADD CONSTRAINT stock_lots_job_fk FOREIGN KEY (job_card_id) REFERENCES job_cards(id);
ALTER TABLE stock_reservations
    ADD CONSTRAINT stock_reservations_job_fk FOREIGN KEY (job_card_id) REFERENCES job_cards(id);

CREATE TABLE job_card_operations (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_card_id          BIGINT UNSIGNED NOT NULL,
    routing_operation_id BIGINT UNSIGNED,
    sequence_no          SMALLINT UNSIGNED NOT NULL,
    code                 VARCHAR(30)  NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    machine_group_id     BIGINT UNSIGNED,
    machine_id           BIGINT UNSIGNED,
    tool_id              BIGINT UNSIGNED,
    planned_qty          DECIMAL(18,6) NOT NULL DEFAULT 0,
    input_qty            DECIMAL(18,6) NOT NULL DEFAULT 0,
    good_qty             DECIMAL(18,6) NOT NULL DEFAULT 0,
    waste_qty            DECIMAL(18,6) NOT NULL DEFAULT 0,
    planned_minutes      DECIMAL(9,2) NOT NULL DEFAULT 0,
    actual_minutes       DECIMAL(9,2) NOT NULL DEFAULT 0,
    scheduled_start      DATETIME(3),
    scheduled_finish     DATETIME(3),
    started_at           DATETIME(3),
    finished_at          DATETIME(3),
    requires_qc          BOOLEAN NOT NULL DEFAULT FALSE,
    status               VARCHAR(20) NOT NULL DEFAULT 'pending',
    UNIQUE KEY job_card_operations_uq (job_card_id, sequence_no),
    KEY job_card_operations_machine_idx (machine_id, status, scheduled_start),
    KEY job_card_operations_routingop_idx (routing_operation_id),
    KEY job_card_operations_group_idx (machine_group_id),
    KEY job_card_operations_tool_idx (tool_id),
    CONSTRAINT job_card_operations_job_fk       FOREIGN KEY (job_card_id)          REFERENCES job_cards(id) ON DELETE CASCADE,
    CONSTRAINT job_card_operations_routingop_fk FOREIGN KEY (routing_operation_id) REFERENCES routing_operations(id),
    CONSTRAINT job_card_operations_group_fk     FOREIGN KEY (machine_group_id)     REFERENCES machine_groups(id),
    CONSTRAINT job_card_operations_machine_fk   FOREIGN KEY (machine_id)           REFERENCES machines(id),
    CONSTRAINT job_card_operations_tool_fk      FOREIGN KEY (tool_id)              REFERENCES tools(id),
    CONSTRAINT job_card_operations_status_chk CHECK (status IN ('pending','ready','in_progress','paused','completed','skipped','cancelled')),
    CONSTRAINT job_card_operations_output_chk CHECK (good_qty + waste_qty <= input_qty + 0.000001)   -- J3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE operation_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_card_operation_id BIGINT UNSIGNED NOT NULL,
    machine_id            BIGINT UNSIGNED,
    operator_id           BIGINT UNSIGNED,
    shift_id              BIGINT UNSIGNED,
    started_at            DATETIME(3) NOT NULL,
    ended_at              DATETIME(3),
    good_qty              DECIMAL(18,6) NOT NULL DEFAULT 0,
    waste_qty             DECIMAL(18,6) NOT NULL DEFAULT 0,
    input_lot_id          BIGINT UNSIGNED,
    output_lot_id         BIGINT UNSIGNED,
    remarks               VARCHAR(255),
    created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by            BIGINT UNSIGNED,
    KEY operation_logs_op_idx (job_card_operation_id, started_at),
    KEY operation_logs_machine_idx (machine_id, started_at),
    KEY operation_logs_operator_idx (operator_id, started_at),
    KEY operation_logs_shift_idx (shift_id),
    KEY operation_logs_inlot_idx (input_lot_id),
    KEY operation_logs_outlot_idx (output_lot_id),
    KEY operation_logs_creator_idx (created_by),
    CONSTRAINT operation_logs_op_fk       FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id) ON DELETE CASCADE,
    CONSTRAINT operation_logs_machine_fk  FOREIGN KEY (machine_id)            REFERENCES machines(id),
    CONSTRAINT operation_logs_operator_fk FOREIGN KEY (operator_id)           REFERENCES employees(id),
    CONSTRAINT operation_logs_shift_fk    FOREIGN KEY (shift_id)              REFERENCES shifts(id),
    CONSTRAINT operation_logs_inlot_fk    FOREIGN KEY (input_lot_id)          REFERENCES stock_lots(id),
    CONSTRAINT operation_logs_outlot_fk   FOREIGN KEY (output_lot_id)         REFERENCES stock_lots(id),
    CONSTRAINT operation_logs_creator_fk  FOREIGN KEY (created_by)            REFERENCES users(id),
    CONSTRAINT operation_logs_time_chk CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE material_issues (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(30),
    job_card_id  BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    issued_on    DATE NOT NULL DEFAULT (CURRENT_DATE),
    issue_type   VARCHAR(20) NOT NULL DEFAULT 'issue',
    status       VARCHAR(20) NOT NULL DEFAULT 'draft',
    issued_by    BIGINT UNSIGNED,
    received_by  BIGINT UNSIGNED,
    remarks      VARCHAR(255),
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY material_issues_number_uq (number),
    KEY material_issues_job_idx (job_card_id),
    KEY material_issues_warehouse_idx (warehouse_id),
    KEY material_issues_issuer_idx (issued_by),
    KEY material_issues_receiver_idx (received_by),
    CONSTRAINT material_issues_job_fk       FOREIGN KEY (job_card_id)  REFERENCES job_cards(id),
    CONSTRAINT material_issues_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT material_issues_issuer_fk    FOREIGN KEY (issued_by)    REFERENCES users(id),
    CONSTRAINT material_issues_receiver_fk  FOREIGN KEY (received_by)  REFERENCES employees(id),
    CONSTRAINT material_issues_type_chk   CHECK (issue_type IN ('issue','return')),
    CONSTRAINT material_issues_status_chk CHECK (status IN ('draft','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE material_issue_lines (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    material_issue_id    BIGINT UNSIGNED NOT NULL,
    line_no              SMALLINT UNSIGNED NOT NULL,
    item_id              BIGINT UNSIGNED NOT NULL,
    lot_id               BIGINT UNSIGNED NOT NULL,
    uom_id               BIGINT UNSIGNED NOT NULL,
    qty                  DECIMAL(18,6) NOT NULL,
    unit_cost            DECIMAL(18,4) NOT NULL DEFAULT 0,
    fifo_override_reason VARCHAR(255),                     -- BR-37
    UNIQUE KEY material_issue_lines_uq (material_issue_id, line_no),
    KEY material_issue_lines_item_idx (item_id),
    KEY material_issue_lines_lot_idx (lot_id),
    KEY material_issue_lines_uom_idx (uom_id),
    CONSTRAINT material_issue_lines_issue_fk FOREIGN KEY (material_issue_id) REFERENCES material_issues(id) ON DELETE CASCADE,
    CONSTRAINT material_issue_lines_item_fk  FOREIGN KEY (item_id)           REFERENCES items(id),
    CONSTRAINT material_issue_lines_lot_fk   FOREIGN KEY (lot_id)            REFERENCES stock_lots(id),
    CONSTRAINT material_issue_lines_uom_fk   FOREIGN KEY (uom_id)            REFERENCES uoms(id),
    CONSTRAINT material_issue_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE downtime_reasons (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    category   VARCHAR(20)  NOT NULL,
    is_planned BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY downtime_reasons_code_uq (code),
    CONSTRAINT downtime_reasons_category_chk CHECK (category IN ('mechanical','electrical','material','quality','changeover','power','manpower','planned','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE downtime_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id            BIGINT UNSIGNED NOT NULL,
    job_card_operation_id BIGINT UNSIGNED,
    downtime_reason_id    BIGINT UNSIGNED NOT NULL,
    shift_id              BIGINT UNSIGNED,
    started_at            DATETIME(3) NOT NULL,
    ended_at              DATETIME(3),
    minutes               DECIMAL(9,2),
    reported_by           BIGINT UNSIGNED,
    remarks               VARCHAR(255),
    KEY downtime_logs_machine_idx (machine_id, started_at),
    KEY downtime_logs_op_idx (job_card_operation_id),
    KEY downtime_logs_reason_idx (downtime_reason_id),
    KEY downtime_logs_shift_idx (shift_id),
    KEY downtime_logs_reporter_idx (reported_by),
    CONSTRAINT downtime_logs_machine_fk  FOREIGN KEY (machine_id)            REFERENCES machines(id),
    CONSTRAINT downtime_logs_op_fk       FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id),
    CONSTRAINT downtime_logs_reason_fk   FOREIGN KEY (downtime_reason_id)    REFERENCES downtime_reasons(id),
    CONSTRAINT downtime_logs_shift_fk    FOREIGN KEY (shift_id)              REFERENCES shifts(id),
    CONSTRAINT downtime_logs_reporter_fk FOREIGN KEY (reported_by)           REFERENCES employees(id),
    CONSTRAINT downtime_logs_time_chk CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE waste_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_card_id           BIGINT UNSIGNED,
    job_card_operation_id BIGINT UNSIGNED,
    item_id               BIGINT UNSIGNED,
    lot_id                BIGINT UNSIGNED,
    waste_type            VARCHAR(20) NOT NULL,
    qty                   DECIMAL(18,6) NOT NULL,
    uom_id                BIGINT UNSIGNED,
    value                 DECIMAL(18,4) NOT NULL DEFAULT 0,
    occurred_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    reported_by           BIGINT UNSIGNED,
    remarks               VARCHAR(255),
    KEY waste_logs_job_idx (job_card_id, occurred_at),
    KEY waste_logs_op_idx (job_card_operation_id),
    KEY waste_logs_item_idx (item_id),
    KEY waste_logs_lot_idx (lot_id),
    KEY waste_logs_uom_idx (uom_id),
    KEY waste_logs_reporter_idx (reported_by),
    CONSTRAINT waste_logs_job_fk      FOREIGN KEY (job_card_id)           REFERENCES job_cards(id),
    CONSTRAINT waste_logs_op_fk       FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id),
    CONSTRAINT waste_logs_item_fk     FOREIGN KEY (item_id)               REFERENCES items(id),
    CONSTRAINT waste_logs_lot_fk      FOREIGN KEY (lot_id)                REFERENCES stock_lots(id),
    CONSTRAINT waste_logs_uom_fk      FOREIGN KEY (uom_id)                REFERENCES uoms(id),
    CONSTRAINT waste_logs_reporter_fk FOREIGN KEY (reported_by)           REFERENCES employees(id),
    CONSTRAINT waste_logs_qty_chk  CHECK (qty > 0),
    CONSTRAINT waste_logs_type_chk CHECK (waste_type IN ('setup','shade','print_defect','weave_defect','cutting','edge_trim','damaged','expired','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tool_usages (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tool_id               BIGINT UNSIGNED NOT NULL,
    job_card_operation_id BIGINT UNSIGNED,
    impressions           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    used_on               DATE NOT NULL DEFAULT (CURRENT_DATE),
    remarks               VARCHAR(255),
    KEY tool_usages_tool_idx (tool_id, used_on),
    KEY tool_usages_op_idx (job_card_operation_id),
    CONSTRAINT tool_usages_tool_fk FOREIGN KEY (tool_id)               REFERENCES tools(id),
    CONSTRAINT tool_usages_op_fk   FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 10. QUALITY & LABORATORY
-- =====================================================================

CREATE TABLE defects (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(120) NOT NULL,
    process   VARCHAR(20),
    severity  VARCHAR(10)  NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY defects_code_uq (code),
    CONSTRAINT defects_process_chk  CHECK (process IS NULL OR process IN ('weaving','printing','cutting','folding','packing','material','general')),
    CONSTRAINT defects_severity_fk FOREIGN KEY (severity) REFERENCES defect_severities(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE aql_plans (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    standard         VARCHAR(30) NOT NULL DEFAULT 'ISO 2859-1',
    inspection_level VARCHAR(10) NOT NULL DEFAULT 'II',
    aql_value        DECIMAL(5,2) NOT NULL DEFAULT 2.5,
    lot_size_from    BIGINT UNSIGNED NOT NULL,
    lot_size_to      BIGINT UNSIGNED NOT NULL,
    sample_size      INT UNSIGNED NOT NULL,
    accept_number    INT UNSIGNED NOT NULL,
    reject_number    INT UNSIGNED NOT NULL,
    UNIQUE KEY aql_plans_uq (standard, inspection_level, aql_value, lot_size_from),
    CONSTRAINT aql_plans_range_chk  CHECK (lot_size_to >= lot_size_from),
    CONSTRAINT aql_plans_sample_chk CHECK (sample_size > 0),
    CONSTRAINT aql_plans_number_chk CHECK (reject_number > accept_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE qc_inspections (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number                VARCHAR(30),
    stage                 VARCHAR(20) NOT NULL,
    job_card_id           BIGINT UNSIGNED,
    job_card_operation_id BIGINT UNSIGNED,
    grn_line_id           BIGINT UNSIGNED,
    lot_id                BIGINT UNSIGNED,
    aql_plan_id           BIGINT UNSIGNED,
    inspected_on          DATE NOT NULL DEFAULT (CURRENT_DATE),
    inspector_id          BIGINT UNSIGNED,
    lot_size              BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sample_size           INT UNSIGNED NOT NULL DEFAULT 0,
    critical_found        INT UNSIGNED NOT NULL DEFAULT 0,
    major_found           INT UNSIGNED NOT NULL DEFAULT 0,
    minor_found           INT UNSIGNED NOT NULL DEFAULT 0,
    accept_number         INT UNSIGNED,
    reject_number         INT UNSIGNED,
    dhu                   DECIMAL(9,4),
    result                VARCHAR(30) NOT NULL DEFAULT 'pending',
    disposition           VARCHAR(20),
    disposition_ref       VARCHAR(180),
    remarks               VARCHAR(500),
    created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by            BIGINT UNSIGNED,
    UNIQUE KEY qc_inspections_number_uq (number),
    KEY qc_inspections_job_idx (job_card_id, stage),
    KEY qc_inspections_op_idx (job_card_operation_id),
    KEY qc_inspections_grnline_idx (grn_line_id),
    KEY qc_inspections_lot_idx (lot_id),
    KEY qc_inspections_plan_idx (aql_plan_id),
    KEY qc_inspections_inspector_idx (inspector_id),
    KEY qc_inspections_creator_idx (created_by),
    CONSTRAINT qc_inspections_job_fk       FOREIGN KEY (job_card_id)           REFERENCES job_cards(id),
    CONSTRAINT qc_inspections_op_fk        FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id),
    CONSTRAINT qc_inspections_grnline_fk   FOREIGN KEY (grn_line_id)           REFERENCES grn_lines(id),
    CONSTRAINT qc_inspections_lot_fk       FOREIGN KEY (lot_id)                REFERENCES stock_lots(id),
    CONSTRAINT qc_inspections_plan_fk      FOREIGN KEY (aql_plan_id)           REFERENCES aql_plans(id),
    CONSTRAINT qc_inspections_inspector_fk FOREIGN KEY (inspector_id)          REFERENCES employees(id),
    CONSTRAINT qc_inspections_creator_fk   FOREIGN KEY (created_by)            REFERENCES users(id),
    CONSTRAINT qc_inspections_stage_chk  CHECK (stage IN ('incoming','in_process','final','pre_shipment','customer')),
    CONSTRAINT qc_inspections_result_chk CHECK (result IN ('pending','accepted','rejected','accepted_with_concession')),
    CONSTRAINT qc_inspections_disp_fk    FOREIGN KEY (disposition) REFERENCES qc_dispositions(code),
    -- QC2 / BR-33: a rejected lot may never leave QC without a disposition
    CONSTRAINT qc_inspections_rejected_chk CHECK (result <> 'rejected' OR disposition IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE qc_inspection_defects (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    qc_inspection_id BIGINT UNSIGNED NOT NULL,
    defect_id        BIGINT UNSIGNED NOT NULL,
    qty              INT UNSIGNED NOT NULL,
    remarks          VARCHAR(255),
    UNIQUE KEY qc_inspection_defects_uq (qc_inspection_id, defect_id),
    KEY qc_inspection_defects_defect_idx (defect_id),
    CONSTRAINT qc_inspection_defects_insp_fk   FOREIGN KEY (qc_inspection_id) REFERENCES qc_inspections(id) ON DELETE CASCADE,
    CONSTRAINT qc_inspection_defects_defect_fk FOREIGN KEY (defect_id)        REFERENCES defects(id),
    CONSTRAINT qc_inspection_defects_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lab_tests (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(20)  NOT NULL,
    name               VARCHAR(150) NOT NULL,
    method             VARCHAR(60),
    scale              VARCHAR(20)  NOT NULL,
    default_pass_value VARCHAR(40),
    unit               VARCHAR(20),
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY lab_tests_code_uq (code),
    CONSTRAINT lab_tests_scale_chk CHECK (scale IN ('grey_1_5','percent','delta_e','pass_fail','numeric'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_test_requirements (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    product_id   BIGINT UNSIGNED,
    lab_test_id  BIGINT UNSIGNED NOT NULL,
    pass_value   VARCHAR(40) NOT NULL,
    is_mandatory BOOLEAN NOT NULL DEFAULT TRUE,
    -- unique over a nullable product_id (§15)
    product_key  BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(product_id, 0)) STORED,
    UNIQUE KEY customer_test_requirements_uq (customer_id, product_key, lab_test_id),
    KEY customer_test_requirements_product_idx (product_id),
    KEY customer_test_requirements_test_idx (lab_test_id),
    CONSTRAINT customer_test_requirements_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT customer_test_requirements_product_fk  FOREIGN KEY (product_id)  REFERENCES products(id),
    CONSTRAINT customer_test_requirements_test_fk     FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE test_reports (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number         VARCHAR(30),
    lot_id         BIGINT UNSIGNED,
    job_card_id    BIGINT UNSIGNED,
    product_id     BIGINT UNSIGNED,
    customer_id    BIGINT UNSIGNED,
    tested_on      DATE NOT NULL DEFAULT (CURRENT_DATE),
    technician_id  BIGINT UNSIGNED,
    overall_result VARCHAR(10) NOT NULL DEFAULT 'pending',
    status         VARCHAR(20) NOT NULL DEFAULT 'draft',
    issued_at      DATETIME(3),
    remarks        VARCHAR(500),
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by     BIGINT UNSIGNED,
    UNIQUE KEY test_reports_number_uq (number),
    KEY test_reports_lot_idx (lot_id),
    KEY test_reports_job_idx (job_card_id),
    KEY test_reports_product_idx (product_id),
    KEY test_reports_customer_idx (customer_id, tested_on),
    KEY test_reports_tech_idx (technician_id),
    KEY test_reports_creator_idx (created_by),
    CONSTRAINT test_reports_lot_fk      FOREIGN KEY (lot_id)        REFERENCES stock_lots(id),
    CONSTRAINT test_reports_job_fk      FOREIGN KEY (job_card_id)   REFERENCES job_cards(id),
    CONSTRAINT test_reports_product_fk  FOREIGN KEY (product_id)    REFERENCES products(id),
    CONSTRAINT test_reports_customer_fk FOREIGN KEY (customer_id)   REFERENCES customers(id),
    CONSTRAINT test_reports_tech_fk     FOREIGN KEY (technician_id) REFERENCES employees(id),
    CONSTRAINT test_reports_creator_fk  FOREIGN KEY (created_by)    REFERENCES users(id),
    CONSTRAINT test_reports_result_chk CHECK (overall_result IN ('pending','pass','fail')),
    CONSTRAINT test_reports_status_chk CHECK (status IN ('draft','issued','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE test_report_lines (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    test_report_id BIGINT UNSIGNED NOT NULL,
    lab_test_id    BIGINT UNSIGNED NOT NULL,
    result_value   VARCHAR(40) NOT NULL,
    pass_value     VARCHAR(40),
    result         VARCHAR(10) NOT NULL,
    remarks        VARCHAR(255),
    UNIQUE KEY test_report_lines_uq (test_report_id, lab_test_id),
    KEY test_report_lines_test_idx (lab_test_id),
    CONSTRAINT test_report_lines_report_fk FOREIGN KEY (test_report_id) REFERENCES test_reports(id) ON DELETE CASCADE,
    CONSTRAINT test_report_lines_test_fk   FOREIGN KEY (lab_test_id)    REFERENCES lab_tests(id),
    CONSTRAINT test_report_lines_result_chk CHECK (result IN ('pass','fail','na'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ncrs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    source           VARCHAR(30) NOT NULL,
    qc_inspection_id BIGINT UNSIGNED,
    job_card_id      BIGINT UNSIGNED,
    supplier_id      BIGINT UNSIGNED,
    customer_id      BIGINT UNSIGNED,
    raised_on        DATE NOT NULL DEFAULT (CURRENT_DATE),
    description      TEXT NOT NULL,
    severity         VARCHAR(10) NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'open',
    closed_on        DATE,
    raised_by        BIGINT UNSIGNED,
    owner_id         BIGINT UNSIGNED,
    UNIQUE KEY ncrs_number_uq (number),
    KEY ncrs_status_idx (status, raised_on),
    KEY ncrs_insp_idx (qc_inspection_id),
    KEY ncrs_job_idx (job_card_id),
    KEY ncrs_supplier_idx (supplier_id),
    KEY ncrs_customer_idx (customer_id),
    KEY ncrs_raiser_idx (raised_by),
    KEY ncrs_owner_idx (owner_id),
    CONSTRAINT ncrs_insp_fk     FOREIGN KEY (qc_inspection_id) REFERENCES qc_inspections(id),
    CONSTRAINT ncrs_job_fk      FOREIGN KEY (job_card_id)      REFERENCES job_cards(id),
    CONSTRAINT ncrs_supplier_fk FOREIGN KEY (supplier_id)      REFERENCES suppliers(id),
    CONSTRAINT ncrs_customer_fk FOREIGN KEY (customer_id)      REFERENCES customers(id),
    CONSTRAINT ncrs_raiser_fk   FOREIGN KEY (raised_by)        REFERENCES users(id),
    CONSTRAINT ncrs_owner_fk    FOREIGN KEY (owner_id)         REFERENCES users(id),
    CONSTRAINT ncrs_source_chk   CHECK (source IN ('incoming','in_process','final','customer_complaint','audit','lab')),
    CONSTRAINT ncrs_severity_fk FOREIGN KEY (severity) REFERENCES defect_severities(code),
    CONSTRAINT ncrs_status_chk   CHECK (status IN ('open','investigating','action_taken','verified','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE capas (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ncr_id         BIGINT UNSIGNED NOT NULL,
    kind           VARCHAR(20) NOT NULL,
    root_cause     TEXT,
    action         TEXT NOT NULL,
    responsible_id BIGINT UNSIGNED,
    due_date       DATE,
    completed_on   DATE,
    effectiveness  VARCHAR(20),
    status         VARCHAR(20) NOT NULL DEFAULT 'open',
    KEY capas_ncr_idx (ncr_id),
    KEY capas_owner_idx (responsible_id),
    CONSTRAINT capas_ncr_fk   FOREIGN KEY (ncr_id)         REFERENCES ncrs(id) ON DELETE CASCADE,
    CONSTRAINT capas_owner_fk FOREIGN KEY (responsible_id) REFERENCES users(id),
    CONSTRAINT capas_kind_chk   CHECK (kind IN ('corrective','preventive')),
    CONSTRAINT capas_eff_chk    CHECK (effectiveness IS NULL OR effectiveness IN ('effective','not_effective','pending_review')),
    CONSTRAINT capas_status_chk CHECK (status IN ('open','in_progress','completed','verified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 11. COMPLIANCE & CHAIN OF CUSTODY
-- =====================================================================

CREATE TABLE certifications (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scheme            VARCHAR(20) NOT NULL,
    certificate_no    VARCHAR(80) NOT NULL,
    issuing_body      VARCHAR(150),
    issued_on         DATE NOT NULL,
    expires_on        DATE NOT NULL,
    scope_description VARCHAR(500),
    document_path     VARCHAR(500),
    reminder_days     SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    status            VARCHAR(20) NOT NULL DEFAULT 'active',
    UNIQUE KEY certifications_uq (scheme, certificate_no),
    KEY certifications_expiry_idx (expires_on, status),
    CONSTRAINT certifications_scheme_chk CHECK (scheme IN ('FSC','GRS','OEKO_TEX','BSCI','SMETA','ISO_9001','ISO_14001','SCOPE','OTHER')),
    CONSTRAINT certifications_status_chk CHECK (status IN ('active','expired','suspended','withdrawn')),
    CONSTRAINT certifications_dates_chk  CHECK (expires_on > issued_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE certification_scopes (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    certification_id      BIGINT UNSIGNED NOT NULL,
    product_type          VARCHAR(20),
    item_category_id      BIGINT UNSIGNED,
    min_claim_pct         DECIMAL(9,4) NOT NULL DEFAULT 0,     -- BR-41
    labelled_claim_pct    DECIMAL(9,4) NOT NULL DEFAULT 50,
    max_conversion_factor DECIMAL(9,4) NOT NULL DEFAULT 1,     -- BR-42
    KEY certification_scopes_cert_idx (certification_id),
    KEY certification_scopes_category_idx (item_category_id),
    CONSTRAINT certification_scopes_cert_fk     FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE,
    CONSTRAINT certification_scopes_category_fk FOREIGN KEY (item_category_id) REFERENCES item_categories(id),
    CONSTRAINT certification_scopes_type_fk     FOREIGN KEY (product_type)     REFERENCES product_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE coc_transactions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    certification_id BIGINT UNSIGNED,
    scheme           VARCHAR(20) NOT NULL,
    direction        VARCHAR(20) NOT NULL,
    period_year      SMALLINT UNSIGNED NOT NULL,
    period_month     TINYINT UNSIGNED NOT NULL,
    grn_line_id      BIGINT UNSIGNED,
    lot_id           BIGINT UNSIGNED,
    job_card_id      BIGINT UNSIGNED,
    packing_list_id  BIGINT UNSIGNED,                 -- FK added in §12 (circular)
    item_id          BIGINT UNSIGNED,
    product_id       BIGINT UNSIGNED,
    qty              DECIMAL(18,6) NOT NULL,
    uom_id           BIGINT UNSIGNED,
    claim_pct        DECIMAL(9,4) NOT NULL DEFAULT 0,
    document_no      VARCHAR(80),
    is_locked        BOOLEAN NOT NULL DEFAULT FALSE,  -- C3
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    KEY coc_period_idx (scheme, period_year, period_month, direction),
    KEY coc_cert_idx (certification_id),
    KEY coc_grnline_idx (grn_line_id),
    KEY coc_lot_idx (lot_id),
    KEY coc_job_idx (job_card_id),
    KEY coc_packing_idx (packing_list_id),
    KEY coc_item_idx (item_id),
    KEY coc_product_idx (product_id),
    KEY coc_uom_idx (uom_id),
    KEY coc_creator_idx (created_by),
    CONSTRAINT coc_cert_fk    FOREIGN KEY (certification_id) REFERENCES certifications(id),
    CONSTRAINT coc_grnline_fk FOREIGN KEY (grn_line_id)      REFERENCES grn_lines(id),
    CONSTRAINT coc_lot_fk     FOREIGN KEY (lot_id)           REFERENCES stock_lots(id),
    CONSTRAINT coc_job_fk     FOREIGN KEY (job_card_id)      REFERENCES job_cards(id),
    CONSTRAINT coc_item_fk    FOREIGN KEY (item_id)          REFERENCES items(id),
    CONSTRAINT coc_product_fk FOREIGN KEY (product_id)       REFERENCES products(id),
    CONSTRAINT coc_uom_fk     FOREIGN KEY (uom_id)           REFERENCES uoms(id),
    CONSTRAINT coc_creator_fk FOREIGN KEY (created_by)       REFERENCES users(id),
    CONSTRAINT coc_direction_chk CHECK (direction IN ('input','conversion','output')),
    CONSTRAINT coc_month_chk     CHECK (period_month BETWEEN 1 AND 12),
    CONSTRAINT coc_qty_chk       CHECK (qty > 0),
    CONSTRAINT coc_claim_chk     CHECK (claim_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
-- =====================================================================

CREATE TABLE fg_receipts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    job_card_id      BIGINT UNSIGNED NOT NULL,
    warehouse_id     BIGINT UNSIGNED NOT NULL,
    lot_id           BIGINT UNSIGNED,
    received_on      DATE NOT NULL DEFAULT (CURRENT_DATE),
    qty              DECIMAL(18,6) NOT NULL,
    qc_inspection_id BIGINT UNSIGNED,
    grade            VARCHAR(10) NOT NULL DEFAULT 'A',
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    UNIQUE KEY fg_receipts_number_uq (number),
    KEY fg_receipts_job_idx (job_card_id),
    KEY fg_receipts_warehouse_idx (warehouse_id),
    KEY fg_receipts_lot_idx (lot_id),
    KEY fg_receipts_insp_idx (qc_inspection_id),
    KEY fg_receipts_creator_idx (created_by),
    CONSTRAINT fg_receipts_job_fk       FOREIGN KEY (job_card_id)      REFERENCES job_cards(id),
    CONSTRAINT fg_receipts_warehouse_fk FOREIGN KEY (warehouse_id)     REFERENCES warehouses(id),
    CONSTRAINT fg_receipts_lot_fk       FOREIGN KEY (lot_id)           REFERENCES stock_lots(id),
    CONSTRAINT fg_receipts_insp_fk      FOREIGN KEY (qc_inspection_id) REFERENCES qc_inspections(id),
    CONSTRAINT fg_receipts_creator_fk   FOREIGN KEY (created_by)       REFERENCES users(id),
    CONSTRAINT fg_receipts_qty_chk    CHECK (qty > 0),
    CONSTRAINT fg_receipts_grade_chk  CHECK (grade IN ('A','B','reject')),
    CONSTRAINT fg_receipts_status_chk CHECK (status IN ('draft','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE packing_lists (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    sales_order_id      BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    delivery_address_id BIGINT UNSIGNED,
    packed_on           DATE NOT NULL DEFAULT (CURRENT_DATE),
    total_cartons       INT UNSIGNED NOT NULL DEFAULT 0,
    total_qty           DECIMAL(18,6) NOT NULL DEFAULT 0,
    gross_weight_kg     DECIMAL(12,3),
    net_weight_kg       DECIMAL(12,3),
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    cert_claim_scheme   VARCHAR(20),
    cert_claim_pct      DECIMAL(9,4) NOT NULL DEFAULT 0,
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY packing_lists_number_uq (number),
    KEY packing_lists_customer_idx (customer_id, status),
    KEY packing_lists_order_idx (sales_order_id),
    KEY packing_lists_address_idx (delivery_address_id),
    KEY packing_lists_creator_idx (created_by),
    CONSTRAINT packing_lists_order_fk    FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT packing_lists_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT packing_lists_address_fk  FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT packing_lists_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT packing_lists_status_chk CHECK (status IN ('draft','packed','dispatched','delivered','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE coc_transactions
    ADD CONSTRAINT coc_packing_fk FOREIGN KEY (packing_list_id) REFERENCES packing_lists(id);

CREATE TABLE cartons (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    packing_list_id BIGINT UNSIGNED NOT NULL,
    carton_no       VARCHAR(20) NOT NULL,
    barcode         VARCHAR(64),
    gross_weight_kg DECIMAL(12,3),
    net_weight_kg   DECIMAL(12,3),
    length_cm       DECIMAL(9,2),
    width_cm        DECIMAL(9,2),
    height_cm       DECIMAL(9,2),
    UNIQUE KEY cartons_uq (packing_list_id, carton_no),
    UNIQUE KEY cartons_barcode_uq (barcode),
    CONSTRAINT cartons_packing_fk FOREIGN KEY (packing_list_id) REFERENCES packing_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE carton_contents (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    carton_id           BIGINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED NOT NULL,
    lot_id              BIGINT UNSIGNED,
    colourway           VARCHAR(80),
    qty                 DECIMAL(18,6) NOT NULL,
    bundles             INT UNSIGNED,
    KEY carton_contents_carton_idx (carton_id),
    KEY carton_contents_lot_idx (lot_id),
    KEY carton_contents_soline_idx (sales_order_line_id),
    KEY carton_contents_product_idx (product_id),
    CONSTRAINT carton_contents_carton_fk  FOREIGN KEY (carton_id)           REFERENCES cartons(id) ON DELETE CASCADE,
    CONSTRAINT carton_contents_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT carton_contents_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT carton_contents_lot_fk     FOREIGN KEY (lot_id)              REFERENCES stock_lots(id),
    CONSTRAINT carton_contents_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vehicles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    registration_no VARCHAR(40) NOT NULL,
    kind            VARCHAR(20) NOT NULL,
    capacity_kg     DECIMAL(12,3),
    is_owned        BOOLEAN NOT NULL DEFAULT TRUE,
    fitness_expiry  DATE,
    tax_expiry      DATE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY vehicles_reg_uq (registration_no),
    CONSTRAINT vehicles_kind_chk CHECK (kind IN ('van','pickup','truck','motorcycle','covered_van'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE drivers (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id    BIGINT UNSIGNED,
    name           VARCHAR(150) NOT NULL,
    licence_no     VARCHAR(60),
    licence_expiry DATE,
    phone          VARCHAR(30),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    KEY drivers_employee_idx (employee_id),
    CONSTRAINT drivers_employee_fk FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trips (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number         VARCHAR(30),
    vehicle_id     BIGINT UNSIGNED NOT NULL,
    driver_id      BIGINT UNSIGNED,
    trip_date      DATE NOT NULL DEFAULT (CURRENT_DATE),
    route_zone     VARCHAR(60),
    started_at     DATETIME(3),
    completed_at   DATETIME(3),
    start_odometer DECIMAL(12,2),
    end_odometer   DECIMAL(12,2),
    fuel_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    status         VARCHAR(20) NOT NULL DEFAULT 'planned',
    remarks        VARCHAR(255),
    UNIQUE KEY trips_number_uq (number),
    KEY trips_vehicle_idx (vehicle_id, trip_date),
    KEY trips_driver_idx (driver_id),
    KEY trips_status_idx (status, trip_date),
    CONSTRAINT trips_vehicle_fk FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    CONSTRAINT trips_driver_fk  FOREIGN KEY (driver_id)  REFERENCES drivers(id),
    CONSTRAINT trips_status_chk CHECK (status IN ('planned','loading','in_transit','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_challans (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    packing_list_id     BIGINT UNSIGNED,
    sales_order_id      BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    delivery_address_id BIGINT UNSIGNED,
    trip_id             BIGINT UNSIGNED,
    challan_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    mode                VARCHAR(25) NOT NULL DEFAULT 'own_fleet',
    courier_name        VARCHAR(80),
    tracking_no         VARCHAR(80),
    total_cartons       INT UNSIGNED NOT NULL DEFAULT 0,
    total_qty           DECIMAL(18,6) NOT NULL DEFAULT 0,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    gate_pass_no        VARCHAR(40),
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY delivery_challans_number_uq (number),
    KEY delivery_challans_customer_idx (customer_id, status, challan_date),
    KEY delivery_challans_packing_idx (packing_list_id),
    KEY delivery_challans_order_idx (sales_order_id),
    KEY delivery_challans_address_idx (delivery_address_id),
    KEY delivery_challans_trip_idx (trip_id),
    KEY delivery_challans_creator_idx (created_by),
    CONSTRAINT delivery_challans_packing_fk  FOREIGN KEY (packing_list_id)     REFERENCES packing_lists(id),
    CONSTRAINT delivery_challans_order_fk    FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT delivery_challans_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT delivery_challans_address_fk  FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT delivery_challans_trip_fk     FOREIGN KEY (trip_id)             REFERENCES trips(id),
    CONSTRAINT delivery_challans_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT delivery_challans_mode_chk   CHECK (mode IN ('own_fleet','courier','customer_pickup','freight_forwarder')),
    CONSTRAINT delivery_challans_status_chk CHECK (status IN ('draft','issued','in_transit','delivered','returned','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_challan_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_challan_id BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED NOT NULL,
    lot_id              BIGINT UNSIGNED,
    qty                 DECIMAL(18,6) NOT NULL,
    cartons             INT UNSIGNED,
    UNIQUE KEY delivery_challan_lines_uq (delivery_challan_id, line_no),
    KEY delivery_challan_lines_soline_idx (sales_order_line_id),
    KEY delivery_challan_lines_product_idx (product_id),
    KEY delivery_challan_lines_lot_idx (lot_id),
    CONSTRAINT delivery_challan_lines_challan_fk FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id) ON DELETE CASCADE,
    CONSTRAINT delivery_challan_lines_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT delivery_challan_lines_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT delivery_challan_lines_lot_fk     FOREIGN KEY (lot_id)              REFERENCES stock_lots(id),
    CONSTRAINT delivery_challan_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_stops (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_id             BIGINT UNSIGNED NOT NULL,
    sequence_no         SMALLINT UNSIGNED NOT NULL,
    delivery_challan_id BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED,
    address_id          BIGINT UNSIGNED,
    planned_at          DATETIME(3),
    arrived_at          DATETIME(3),
    departed_at         DATETIME(3),
    status              VARCHAR(25) NOT NULL DEFAULT 'pending',
    received_by_name    VARCHAR(150),
    signature_path      VARCHAR(500),
    photo_path          VARCHAR(500),
    pod_captured_at     DATETIME(3),
    failure_reason      VARCHAR(255),
    UNIQUE KEY trip_stops_uq (trip_id, sequence_no),
    KEY trip_stops_challan_idx (delivery_challan_id),
    KEY trip_stops_customer_idx (customer_id),
    KEY trip_stops_address_idx (address_id),
    CONSTRAINT trip_stops_trip_fk     FOREIGN KEY (trip_id)             REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT trip_stops_challan_fk  FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id),
    CONSTRAINT trip_stops_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT trip_stops_address_fk  FOREIGN KEY (address_id)          REFERENCES customer_addresses(id),
    CONSTRAINT trip_stops_status_chk CHECK (status IN ('pending','arrived','delivered','partially_delivered','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 13. RECEIVABLES / PAYABLES (SUBLEDGER)
-- =====================================================================

CREATE TABLE sales_invoices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    customer_id         BIGINT UNSIGNED NOT NULL,
    sales_order_id      BIGINT UNSIGNED,
    delivery_challan_id BIGINT UNSIGNED,
    invoice_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    due_date            DATE,
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    subtotal            DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total               DECIMAL(18,4) NOT NULL DEFAULT 0,
    received_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    lc_no               VARCHAR(60),
    mushak_no           VARCHAR(60),                       -- VAT challan (Bangladesh)
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY sales_invoices_number_uq (number),
    KEY sales_invoices_outstanding_idx (status, customer_id, due_date),
    KEY sales_invoices_order_idx (sales_order_id),
    KEY sales_invoices_challan_idx (delivery_challan_id),
    KEY sales_invoices_currency_idx (currency_id),
    KEY sales_invoices_creator_idx (created_by),
    CONSTRAINT sales_invoices_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT sales_invoices_order_fk    FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT sales_invoices_challan_fk  FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id),
    CONSTRAINT sales_invoices_currency_fk FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT sales_invoices_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT sales_invoices_status_chk CHECK (status IN ('draft','issued','partially_paid','paid','overdue','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_invoice_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_invoice_id    BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED,
    description         VARCHAR(255) NOT NULL,
    qty                 DECIMAL(18,6) NOT NULL,
    rate_per_m          DECIMAL(18,4) NOT NULL,
    tax_id              BIGINT UNSIGNED,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    amount              DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY sales_invoice_lines_uq (sales_invoice_id, line_no),
    KEY sales_invoice_lines_soline_idx (sales_order_line_id),
    KEY sales_invoice_lines_product_idx (product_id),
    KEY sales_invoice_lines_tax_idx (tax_id),
    CONSTRAINT sales_invoice_lines_invoice_fk FOREIGN KEY (sales_invoice_id)    REFERENCES sales_invoices(id) ON DELETE CASCADE,
    CONSTRAINT sales_invoice_lines_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT sales_invoice_lines_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT sales_invoice_lines_tax_fk     FOREIGN KEY (tax_id)              REFERENCES taxes(id),
    CONSTRAINT sales_invoice_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE receipts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    customer_id      BIGINT UNSIGNED NOT NULL,
    receipt_date     DATE NOT NULL DEFAULT (CURRENT_DATE),
    method           VARCHAR(20) NOT NULL,
    reference_no     VARCHAR(80),
    bank_name        VARCHAR(120),
    currency_id      BIGINT UNSIGNED NOT NULL,
    exchange_rate    DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount           DECIMAL(18,4) NOT NULL,
    allocated_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    remarks          VARCHAR(500),
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    UNIQUE KEY receipts_number_uq (number),
    KEY receipts_customer_idx (customer_id, receipt_date),
    KEY receipts_currency_idx (currency_id),
    KEY receipts_creator_idx (created_by),
    CONSTRAINT receipts_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT receipts_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT receipts_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT receipts_method_chk CHECK (method IN ('cash','cheque','bank_transfer','lc','adjustment')),
    CONSTRAINT receipts_status_chk CHECK (status IN ('draft','posted','bounced','cancelled')),
    CONSTRAINT receipts_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE receipt_allocations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receipt_id       BIGINT UNSIGNED NOT NULL,
    sales_invoice_id BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    UNIQUE KEY receipt_allocations_uq (receipt_id, sales_invoice_id),
    KEY receipt_allocations_invoice_idx (sales_invoice_id),
    CONSTRAINT receipt_allocations_receipt_fk FOREIGN KEY (receipt_id)       REFERENCES receipts(id) ON DELETE CASCADE,
    CONSTRAINT receipt_allocations_invoice_fk FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id),
    CONSTRAINT receipt_allocations_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE credit_notes (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    customer_id      BIGINT UNSIGNED NOT NULL,
    sales_invoice_id BIGINT UNSIGNED,
    note_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason           VARCHAR(30) NOT NULL,
    ncr_id           BIGINT UNSIGNED,
    currency_id      BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by      BIGINT UNSIGNED,
    remarks          VARCHAR(500),
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY credit_notes_number_uq (number),
    KEY credit_notes_customer_idx (customer_id, note_date),
    KEY credit_notes_invoice_idx (sales_invoice_id),
    KEY credit_notes_ncr_idx (ncr_id),
    KEY credit_notes_currency_idx (currency_id),
    KEY credit_notes_approver_idx (approved_by),
    CONSTRAINT credit_notes_customer_fk FOREIGN KEY (customer_id)      REFERENCES customers(id),
    CONSTRAINT credit_notes_invoice_fk  FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id),
    CONSTRAINT credit_notes_ncr_fk      FOREIGN KEY (ncr_id)           REFERENCES ncrs(id),
    CONSTRAINT credit_notes_currency_fk FOREIGN KEY (currency_id)      REFERENCES currencies(id),
    CONSTRAINT credit_notes_approver_fk FOREIGN KEY (approved_by)      REFERENCES users(id),
    CONSTRAINT credit_notes_reason_chk CHECK (reason IN ('quality_claim','short_delivery','rate_difference','return','discount','other')),
    CONSTRAINT credit_notes_status_chk CHECK (status IN ('draft','approved','applied','cancelled')),
    CONSTRAINT credit_notes_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    supplier_id      BIGINT UNSIGNED NOT NULL,
    payment_date     DATE NOT NULL DEFAULT (CURRENT_DATE),
    method           VARCHAR(20) NOT NULL,
    reference_no     VARCHAR(80),
    currency_id      BIGINT UNSIGNED NOT NULL,
    exchange_rate    DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount           DECIMAL(18,4) NOT NULL,
    allocated_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    remarks          VARCHAR(500),
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    UNIQUE KEY payments_number_uq (number),
    KEY payments_supplier_idx (supplier_id, payment_date),
    KEY payments_currency_idx (currency_id),
    KEY payments_creator_idx (created_by),
    CONSTRAINT payments_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT payments_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT payments_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT payments_method_chk CHECK (method IN ('cash','cheque','bank_transfer','lc','adjustment')),
    CONSTRAINT payments_status_chk CHECK (status IN ('draft','posted','cancelled')),
    CONSTRAINT payments_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_allocations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id       BIGINT UNSIGNED NOT NULL,
    supplier_bill_id BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    UNIQUE KEY payment_allocations_uq (payment_id, supplier_bill_id),
    KEY payment_allocations_bill_idx (supplier_bill_id),
    CONSTRAINT payment_allocations_payment_fk FOREIGN KEY (payment_id)       REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT payment_allocations_bill_fk    FOREIGN KEY (supplier_bill_id) REFERENCES supplier_bills(id),
    CONSTRAINT payment_allocations_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- 13a. TRADE FINANCE, IMPORT & EXPENSES
--
-- Yarn, ribbon and ink are imported (00-overview §2), which means the cost
-- of a kilo of yarn is not the supplier's rate: it is that rate plus
-- freight, insurance, duty, C&F and bank charges, and none of those are
-- known on the day the PO is raised. These tables carry the documents
-- between the order and the true cost — the letter of credit, the
-- shipment, the costs against it — and end by writing the landed rate onto
-- the GRN line and the lot (BR-36).
--
-- `expenses` is the general factory expense document. It shares the
-- approval shape of the other money documents and is deliberately separate
-- from `import_costs`: an expense is something somebody pays for, an
-- import cost is something a shipment carries.
-- =====================================================================

CREATE TABLE bank_accounts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    bank_name   VARCHAR(120) NOT NULL,
    branch      VARCHAR(120),
    account_no  VARCHAR(60),
    swift_code  VARCHAR(20),
    currency_id BIGINT UNSIGNED NOT NULL,
    kind        VARCHAR(20) NOT NULL DEFAULT 'current',
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY bank_accounts_code_uq (code),
    KEY bank_accounts_currency_idx (currency_id),
    CONSTRAINT bank_accounts_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT bank_accounts_kind_chk CHECK (kind IN ('current','od','lc','cash','fc'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expense_categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    kind       VARCHAR(20) NOT NULL DEFAULT 'factory',
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY expense_categories_code_uq (code),
    CONSTRAINT expense_categories_kind_chk CHECK (kind IN ('factory','admin','selling','financial','import'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The credit itself. `number` is ours (BR-34); `lc_no` is the bank's, and
-- only exists once the LC is actually opened.
CREATE TABLE letters_of_credit (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number             VARCHAR(30),
    lc_no              VARCHAR(60),
    kind               VARCHAR(20) NOT NULL DEFAULT 'sight',
    supplier_id        BIGINT UNSIGNED NOT NULL,
    bank_account_id    BIGINT UNSIGNED,
    currency_id        BIGINT UNSIGNED NOT NULL,
    exchange_rate      DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount             DECIMAL(18,4) NOT NULL DEFAULT 0,
    tolerance_pct      DECIMAL(9,4)  NOT NULL DEFAULT 0,
    margin_pct         DECIMAL(9,4)  NOT NULL DEFAULT 0,
    tenor_days         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    charges_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    applied_on         DATE,
    issued_on          DATE,
    expiry_date        DATE,
    last_shipment_date DATE,
    incoterm           VARCHAR(20),
    port_of_loading    VARCHAR(80),
    port_of_discharge  VARCHAR(80),
    status             VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks            VARCHAR(500),
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by         BIGINT UNSIGNED,
    UNIQUE KEY letters_of_credit_number_uq (number),
    KEY letters_of_credit_supplier_idx (supplier_id, status),
    KEY letters_of_credit_expiry_idx (status, expiry_date),
    KEY letters_of_credit_bank_idx (bank_account_id),
    KEY letters_of_credit_currency_idx (currency_id),
    KEY letters_of_credit_creator_idx (created_by),
    CONSTRAINT letters_of_credit_supplier_fk FOREIGN KEY (supplier_id)     REFERENCES suppliers(id),
    CONSTRAINT letters_of_credit_bank_fk     FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    CONSTRAINT letters_of_credit_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT letters_of_credit_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT letters_of_credit_kind_chk   CHECK (kind IN ('sight','usance','back_to_back','tt','da','dp')),
    CONSTRAINT letters_of_credit_status_chk CHECK (status IN ('draft','applied','opened','shipped','retired','closed','cancelled')),
    CONSTRAINT letters_of_credit_amount_chk CHECK (amount >= 0),
    -- The bank will not accept a shipment date past expiry, so neither do we.
    CONSTRAINT letters_of_credit_dates_chk CHECK (
        expiry_date IS NULL OR last_shipment_date IS NULL OR last_shipment_date <= expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One LC commonly covers several POs to the same supplier.
CREATE TABLE lc_purchase_orders (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lc_id          BIGINT UNSIGNED NOT NULL,
    po_id          BIGINT UNSIGNED NOT NULL,
    covered_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY lc_purchase_orders_uq (lc_id, po_id),
    KEY lc_purchase_orders_po_idx (po_id),
    CONSTRAINT lc_purchase_orders_lc_fk FOREIGN KEY (lc_id) REFERENCES letters_of_credit(id) ON DELETE CASCADE,
    CONSTRAINT lc_purchase_orders_po_fk FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Amendments are appended, never edited into the LC: what the bank charged
-- for and when the date moved is the whole point of the record.
CREATE TABLE lc_amendments (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lc_id                   BIGINT UNSIGNED NOT NULL,
    amendment_no            SMALLINT UNSIGNED NOT NULL,
    amended_on              DATE NOT NULL DEFAULT (CURRENT_DATE),
    amount_delta            DECIMAL(18,4) NOT NULL DEFAULT 0,
    new_expiry_date         DATE,
    new_last_shipment_date  DATE,
    charges_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    narrative               VARCHAR(500),
    created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by              BIGINT UNSIGNED,
    UNIQUE KEY lc_amendments_uq (lc_id, amendment_no),
    KEY lc_amendments_creator_idx (created_by),
    CONSTRAINT lc_amendments_lc_fk      FOREIGN KEY (lc_id)      REFERENCES letters_of_credit(id) ON DELETE CASCADE,
    CONSTRAINT lc_amendments_creator_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE import_shipments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number            VARCHAR(30),
    lc_id             BIGINT UNSIGNED,
    supplier_id       BIGINT UNSIGNED NOT NULL,
    invoice_no        VARCHAR(60),
    invoice_date      DATE,
    transport_doc_no  VARCHAR(60),
    mode              VARCHAR(20) NOT NULL DEFAULT 'sea',
    carrier           VARCHAR(120),
    etd               DATE,
    eta               DATE,
    arrived_on        DATE,
    cleared_on        DATE,
    bill_of_entry     VARCHAR(60),
    be_date           DATE,
    port_of_loading   VARCHAR(80),
    port_of_discharge VARCHAR(80),
    incoterm          VARCHAR(20),
    currency_id       BIGINT UNSIGNED NOT NULL,
    exchange_rate     DECIMAL(18,8) NOT NULL DEFAULT 1,
    goods_value       DECIMAL(18,4) NOT NULL DEFAULT 0,
    cost_total        DECIMAL(18,4) NOT NULL DEFAULT 0,
    allocated_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    status            VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks           VARCHAR(500),
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        BIGINT UNSIGNED,
    UNIQUE KEY import_shipments_number_uq (number),
    KEY import_shipments_supplier_idx (supplier_id, status),
    KEY import_shipments_lc_idx (lc_id),
    KEY import_shipments_eta_idx (status, eta),
    KEY import_shipments_currency_idx (currency_id),
    KEY import_shipments_creator_idx (created_by),
    CONSTRAINT import_shipments_lc_fk       FOREIGN KEY (lc_id)       REFERENCES letters_of_credit(id),
    CONSTRAINT import_shipments_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT import_shipments_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT import_shipments_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT import_shipments_mode_chk   CHECK (mode IN ('sea','air','road','rail','courier')),
    CONSTRAINT import_shipments_status_chk CHECK (status IN ('draft','in_transit','arrived','cleared','costed','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    expense_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    expense_category_id BIGINT UNSIGNED NOT NULL,
    factory_unit_id     BIGINT UNSIGNED,
    department_id       BIGINT UNSIGNED,
    supplier_id         BIGINT UNSIGNED,
    import_shipment_id  BIGINT UNSIGNED,
    payee               VARCHAR(180) NOT NULL,
    description         VARCHAR(500),
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount              DECIMAL(18,4) NOT NULL,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total               DECIMAL(18,4) NOT NULL DEFAULT 0,
    method              VARCHAR(20) NOT NULL DEFAULT 'cash',
    bank_account_id     BIGINT UNSIGNED,
    reference_no        VARCHAR(80),
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by         BIGINT UNSIGNED,
    approved_at         DATETIME(3),
    paid_on             DATE,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY expenses_number_uq (number),
    KEY expenses_date_idx (expense_date, status),
    KEY expenses_category_idx (expense_category_id, expense_date),
    KEY expenses_unit_idx (factory_unit_id),
    KEY expenses_department_idx (department_id),
    KEY expenses_supplier_idx (supplier_id),
    KEY expenses_shipment_idx (import_shipment_id),
    KEY expenses_currency_idx (currency_id),
    KEY expenses_bank_idx (bank_account_id),
    KEY expenses_approver_idx (approved_by),
    KEY expenses_creator_idx (created_by),
    CONSTRAINT expenses_category_fk FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id),
    CONSTRAINT expenses_unit_fk     FOREIGN KEY (factory_unit_id)     REFERENCES factory_units(id),
    CONSTRAINT expenses_dept_fk     FOREIGN KEY (department_id)       REFERENCES departments(id),
    CONSTRAINT expenses_supplier_fk FOREIGN KEY (supplier_id)         REFERENCES suppliers(id),
    CONSTRAINT expenses_shipment_fk FOREIGN KEY (import_shipment_id)  REFERENCES import_shipments(id),
    CONSTRAINT expenses_currency_fk FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT expenses_bank_fk     FOREIGN KEY (bank_account_id)     REFERENCES bank_accounts(id),
    CONSTRAINT expenses_approver_fk FOREIGN KEY (approved_by)         REFERENCES users(id),
    CONSTRAINT expenses_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT expenses_method_chk CHECK (method IN ('cash','cheque','bank_transfer','card','adjustment')),
    CONSTRAINT expenses_status_chk CHECK (status IN ('draft','pending_approval','approved','paid','cancelled')),
    CONSTRAINT expenses_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What a shipment costs beyond the goods. `is_allocable` separates the
-- costs that belong in inventory (freight, duty, C&F) from the ones that
-- do not (a demurrage penalty is a period cost, not part of a kilo of yarn).
CREATE TABLE import_costs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id   BIGINT UNSIGNED NOT NULL,
    cost_type     VARCHAR(30) NOT NULL,
    description   VARCHAR(180),
    supplier_id   BIGINT UNSIGNED,
    expense_id    BIGINT UNSIGNED,
    reference_no  VARCHAR(80),
    incurred_on   DATE NOT NULL DEFAULT (CURRENT_DATE),
    currency_id   BIGINT UNSIGNED NOT NULL,
    exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount        DECIMAL(18,4) NOT NULL,
    base_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    is_allocable  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED,
    KEY import_costs_shipment_idx (shipment_id, cost_type),
    KEY import_costs_supplier_idx (supplier_id),
    KEY import_costs_expense_idx (expense_id),
    KEY import_costs_currency_idx (currency_id),
    KEY import_costs_creator_idx (created_by),
    CONSTRAINT import_costs_shipment_fk FOREIGN KEY (shipment_id) REFERENCES import_shipments(id) ON DELETE CASCADE,
    CONSTRAINT import_costs_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT import_costs_expense_fk  FOREIGN KEY (expense_id)  REFERENCES expenses(id),
    CONSTRAINT import_costs_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT import_costs_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT import_costs_type_chk CHECK (cost_type IN (
        'freight','insurance','duty','vat','advance_income_tax','c_and_f','port',
        'inland_transport','bank_charge','lc_commission','inspection','demurrage','other')),
    CONSTRAINT import_costs_amount_chk CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The audit of BR-36: which cost, spread over which GRN line, on what
-- basis, for how much. Re-running an allocation replaces these rows, so
-- the arithmetic behind a lot's unit cost can always be shown.
CREATE TABLE landed_cost_allocations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id    BIGINT UNSIGNED NOT NULL,
    import_cost_id BIGINT UNSIGNED NOT NULL,
    grn_line_id    BIGINT UNSIGNED NOT NULL,
    stock_lot_id   BIGINT UNSIGNED,
    basis          VARCHAR(20) NOT NULL DEFAULT 'value',
    basis_value    DECIMAL(18,6) NOT NULL DEFAULT 0,
    amount         DECIMAL(18,4) NOT NULL,
    allocated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY landed_cost_allocations_uq (import_cost_id, grn_line_id),
    KEY landed_cost_allocations_shipment_idx (shipment_id),
    KEY landed_cost_allocations_grnline_idx (grn_line_id),
    KEY landed_cost_allocations_lot_idx (stock_lot_id),
    CONSTRAINT landed_cost_allocations_shipment_fk FOREIGN KEY (shipment_id)    REFERENCES import_shipments(id) ON DELETE CASCADE,
    CONSTRAINT landed_cost_allocations_cost_fk     FOREIGN KEY (import_cost_id) REFERENCES import_costs(id) ON DELETE CASCADE,
    CONSTRAINT landed_cost_allocations_grnline_fk  FOREIGN KEY (grn_line_id)    REFERENCES grn_lines(id),
    CONSTRAINT landed_cost_allocations_lot_fk      FOREIGN KEY (stock_lot_id)   REFERENCES stock_lots(id),
    CONSTRAINT landed_cost_allocations_basis_chk CHECK (basis IN ('value','qty'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A GRN that came off a shipment carries the link, so the allocation knows
-- which receipts share the freight bill.
ALTER TABLE grns
    ADD COLUMN import_shipment_id BIGINT UNSIGNED AFTER po_id,
    ADD KEY grns_shipment_idx (import_shipment_id),
    ADD CONSTRAINT grns_shipment_fk FOREIGN KEY (import_shipment_id) REFERENCES import_shipments(id);

-- =====================================================================
-- 14. DERIVED OBJECTS
--
-- MySQL has no materialised views. `stock_balances` is a summary TABLE
-- maintained by the application (refreshed after posting batches and on a
-- schedule); `v_stock_balances` recomputes the same figures live from the
-- ledger and is the reconciliation reference. See 02-database-schema §4.
-- =====================================================================

CREATE TABLE stock_balances (
    lot_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    item_id        BIGINT UNSIGNED,
    product_id     BIGINT UNSIGNED,
    warehouse_id   BIGINT UNSIGNED NOT NULL,
    lot_no         VARCHAR(40) NOT NULL,
    shade_code     VARCHAR(40),
    cert_scheme    VARCHAR(20),
    cert_claim_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    balance_qty    DECIMAL(18,6) NOT NULL DEFAULT 0,
    received_on    DATE,
    refreshed_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY stock_balances_item_idx (item_id, warehouse_id),
    KEY stock_balances_product_idx (product_id, warehouse_id),
    KEY stock_balances_shade_idx (item_id, shade_code),
    KEY stock_balances_cert_idx (cert_scheme),
    CONSTRAINT stock_balances_lot_fk       FOREIGN KEY (lot_id)       REFERENCES stock_lots(id) ON DELETE CASCADE,
    CONSTRAINT stock_balances_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT stock_balances_product_fk   FOREIGN KEY (product_id)   REFERENCES products(id),
    CONSTRAINT stock_balances_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Authoritative live balance from the append-only ledger (I3).
CREATE VIEW v_stock_balances AS
SELECT
    l.id                            AS lot_id,
    l.lot_no,
    l.item_id,
    l.product_id,
    l.warehouse_id,
    l.shade_code,
    l.cert_scheme,
    l.cert_claim_pct,
    COALESCE(SUM(sl.qty), 0)        AS balance_qty,
    l.received_on
FROM stock_lots l
LEFT JOIN stock_ledger sl ON sl.lot_id = l.id
GROUP BY l.id, l.lot_no, l.item_id, l.product_id, l.warehouse_id,
         l.shade_code, l.cert_scheme, l.cert_claim_pct, l.received_on;

-- Open order book with production progress.
CREATE VIEW v_order_book AS
SELECT
    so.id          AS sales_order_id,
    so.number      AS so_number,
    so.customer_id,
    c.name         AS customer_name,
    sol.id         AS sales_order_line_id,
    sol.product_id,
    p.code         AS product_code,
    sol.ordered_qty,
    sol.produced_qty,
    sol.delivered_qty,
    sol.promised_date,
    so.status      AS order_status,
    sol.status     AS line_status,
    CASE WHEN sol.ordered_qty > 0
         THEN ROUND(sol.delivered_qty / sol.ordered_qty * 100, 2)
         ELSE 0 END AS delivered_pct
FROM sales_orders so
JOIN sales_order_lines sol ON sol.sales_order_id = so.id
JOIN customers c ON c.id = so.customer_id
JOIN products  p ON p.id = sol.product_id
WHERE so.status IN ('confirmed','in_production','partially_delivered');

-- Machine utilisation input (BR-27).
CREATE VIEW v_machine_load AS
SELECT
    jco.machine_id,
    m.code                       AS machine_code,
    DATE(jco.scheduled_start)    AS load_date,
    SUM(jco.planned_minutes)     AS load_minutes,
    COUNT(*)                     AS operation_count
FROM job_card_operations jco
JOIN machines m ON m.id = jco.machine_id
WHERE jco.status IN ('pending','ready','in_progress')
  AND jco.scheduled_start IS NOT NULL
GROUP BY jco.machine_id, m.code, DATE(jco.scheduled_start);

-- Chain-of-custody reconciliation (BR-42).
CREATE VIEW v_coc_reconciliation AS
SELECT
    scheme,
    period_year,
    period_month,
    SUM(CASE WHEN direction = 'input'  THEN qty ELSE 0 END) AS certified_input_qty,
    SUM(CASE WHEN direction = 'output' THEN qty ELSE 0 END) AS certified_output_qty,
    CASE WHEN SUM(CASE WHEN direction = 'input' THEN qty ELSE 0 END) > 0
         THEN ROUND(SUM(CASE WHEN direction = 'output' THEN qty ELSE 0 END)
                  / SUM(CASE WHEN direction = 'input'  THEN qty ELSE 0 END), 4)
         ELSE NULL END                                      AS conversion_factor
FROM coc_transactions
GROUP BY scheme, period_year, period_month;

-- =====================================================================
-- 15. PARTIAL-INDEX EMULATION REGISTER
--
-- PostgreSQL would express these as `CREATE UNIQUE INDEX … WHERE …`.
-- MySQL has no partial indexes, so each is a STORED generated column that
-- evaluates to NULL when the condition is false, plus a UNIQUE key.
-- MySQL treats NULLs as distinct in a UNIQUE index, so only the rows that
-- satisfy the condition compete for uniqueness.
--
--   table               generated column   uniqueness enforced          rule
--   ------------------  -----------------  ---------------------------  --------
--   currencies          base_key           one base currency            —
--   product_specs       current_key        one 'current' spec/product   P2
--   artwork_versions    approved_key       one 'approved' version       A2 / Gate 1
--   boms                active_key         one 'active' BOM/product     PD-3
--
-- And two emulations of "unique over a nullable column", where MySQL's
-- NULL-distinct behaviour would otherwise let duplicates through:
--
--   uom_conversions             item_key     (NULL item = global rule)   BR-3
--   bom_lines                   colour_key   (NULL colour = all colours) —
--   customer_test_requirements  product_key  (NULL product = all)        BR-32
--
-- Application code must never write these columns; they are GENERATED.
-- A migration that changes the underlying status vocabulary must revisit
-- the IF() expression, not only the CHECK constraint.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
