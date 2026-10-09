/**
 * The navigation tree.
 *
 * One pattern: collapsible groups, every screen a row. Nested folders and page-level tab
 * strips both hid screens (Artwork under Products under Floor; Lots behind an Inventory tab).
 * A heading opens the first screen in the group; the chevron peeks without leaving the page.
 * URLs are unchanged, so a deep link still lands.
 *
 * Grouping is the factory's own sequence: sell, buy, make, then stock, quality, dispatch,
 * money, reports. Setup, Access and Activity sit last: the lists, settings, users and audit
 * log are groups like any other, collapsed until opened, so an administrator reaches them
 * from where they are rather than through a separate shell they have to enter and leave.
 * Setup's rows are the list groups themselves (Factory, People …), each opening to its lists;
 * Settings holds the company profile and the number sequences.
 *
 * Each entry names the permissions that make it visible. Visibility is a courtesy; the route
 * middleware is the boundary (06-rbac §7).
 */
export const navigation = [
    {
        label: 'Overview',
        heading: false,
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', permissions: ['report.dashboard', 'report.view_any'] },
        ],
    },
    {
        label: 'Sales',
        items: [
            { label: 'Inquiries', href: '/inquiries', icon: 'inbox', permissions: ['inquiry.view_any'] },
            { label: 'Quotations', href: '/quotations', icon: 'quote', permissions: ['quotation.view_any'] },
            { label: 'Sales orders', href: '/sales-orders', icon: 'order', permissions: ['sales_order.view_any'] },
            { label: 'Customers', href: '/customers', icon: 'customers', permissions: ['customer.view_any'] },
            { label: 'Price lists', href: '/price-lists', icon: 'quote', permissions: ['price_list.view_any'] },
        ],
    },
    {
        label: 'Buying',
        items: [
            { label: 'Requisitions', href: '/purchase-requisitions', icon: 'requisition', permissions: ['purchase_requisition.view_any'] },
            { label: 'RFQs', href: '/rfqs', icon: 'rfq', permissions: ['rfq.view_any'] },
            { label: 'Purchase orders', href: '/purchase-orders', icon: 'purchase-order', permissions: ['purchase_order.view_any'] },
            { label: 'Goods receipts', href: '/grns', icon: 'goods-receipt', permissions: ['grn.view_any'] },
            { label: 'Suppliers', href: '/suppliers', icon: 'supplier', permissions: ['supplier.view_any'] },
            { label: 'Import shipments', href: '/import-shipments', icon: 'ship', permissions: ['import_shipment.view_any'] },
            { label: 'Letters of credit', href: '/letters-of-credit', icon: 'award', permissions: ['letter_of_credit.view_any'] },
        ],
    },
    {
        /*
         * 'Production', not 'Floor'. The group holds desk screens; the operator's kiosk is one
         * row inside it. Naming both 'Floor' made the group read as the terminal's parent and
         * left three names — Floor, Floor terminal, Shop floor — for two different things.
         */
        label: 'Production',
        items: [
            { label: 'Planning board', href: '/planning', icon: 'planning', permissions: ['production_plan.view_any'] },
            { label: 'Job cards', href: '/job-cards', icon: 'job-card', permissions: ['job_card.view_any'] },
            { label: 'Material plan', href: '/mrp', icon: 'mrp', aliases: ['mrp'], permissions: ['mrp.view_any', 'mrp.run'] },
            { label: 'Machines', href: '/machines', icon: 'machine', permissions: ['machine.view_any'] },
            /*
             * The shop-floor terminal is a separate application on a separate device
             * (08-architecture §4) and nothing about it is shared with the desk shell — it has
             * no way back here. Listed so a new planner or supervisor knows it exists, and
             * opened in its own tab so following the link never strands anyone on a badge
             * login screen with the desk session behind it.
             *
             * One entry, one name. It used to be listed here as 'Floor terminal' *and* in the
             * sidebar footer as 'Shop floor' — same URL, same permission, two labels and two
             * icons, which read as two features.
             */
            {
                label: 'Shop floor terminal',
                href: '/floor',
                icon: 'terminal',
                external: true,
                aliases: ['shop floor', 'floor', 'operator', 'badge', 'terminal', 'kiosk'],
                permissions: ['job_card.view_any'],
            },
        ],
    },
    {
        label: 'Products',
        items: [
            { label: 'Products', href: '/products', icon: 'product', permissions: ['product.view_any'] },
            { label: 'Artwork', href: '/artworks', icon: 'artwork', permissions: ['artwork.view_any'] },
            { label: 'BOMs', href: '/boms', icon: 'bom', permissions: ['bom.view_any'] },
            { label: 'Routings', href: '/routings', icon: 'routing', permissions: ['routing.view_any'] },
            { label: 'Tools', href: '/tools', icon: 'tool', permissions: ['tool.view_any'] },
        ],
    },
    {
        label: 'Inventory',
        items: [
            { label: 'On-hand', href: '/stock', icon: 'stock', aliases: ['stock enquiry', 'stock'], permissions: ['stock_lot.view_any'] },
            { label: 'Lots', href: '/lots', icon: 'lot', permissions: ['stock_lot.view_any'] },
            { label: 'Material issues', href: '/material-issues', icon: 'issue', permissions: ['stock_issue.view_any'] },
            { label: 'Transfers', href: '/stock-transfers', icon: 'warehouse', permissions: ['stock_transfer.view_any'] },
            { label: 'Adjustments', href: '/stock-adjustments', icon: 'warning', permissions: ['stock_adjustment.view_any'] },
            { label: 'Physical counts', href: '/physical-counts', icon: 'requisition', permissions: ['physical_count.view_any'] },
            { label: 'Materials', href: '/items', icon: 'item', aliases: ['items'], permissions: ['item.view_any'] },
        ],
    },
    {
        label: 'Quality',
        items: [
            { label: 'Inspections', href: '/qc-inspections', icon: 'inspection', permissions: ['qc_inspection.view_any'] },
            { label: 'NCRs', href: '/ncrs', icon: 'warning', permissions: ['ncr.view_any'] },
            { label: 'Laboratory', href: '/lab', icon: 'lab', permissions: ['test_report.view_any', 'lab_test.view_any'] },
            { label: 'Compliance & CoC', href: '/compliance', icon: 'compliance', permissions: ['coc.view_any', 'certification.view_any'] },
        ],
    },
    {
        label: 'Dispatch',
        items: [
            { label: 'Packing lists', href: '/packing-lists', icon: 'packing', permissions: ['packing_list.view_any'] },
            { label: 'Delivery notes', href: '/delivery-challans', icon: 'challan', aliases: ['challans', 'challan'], permissions: ['delivery_challan.view_any'] },
            { label: 'Trips', href: '/trips', icon: 'trip', permissions: ['trip.view_any'] },
        ],
    },
    {
        label: 'Money',
        items: [
            { label: 'Invoices', href: '/invoices', icon: 'invoice', permissions: ['sales_invoice.view_any'] },
            { label: 'Receipts', href: '/receipts', icon: 'receipt', permissions: ['receipt.view_any'] },
            { label: 'Credit notes', href: '/credit-notes', icon: 'bill', permissions: ['credit_note.view_any'] },
            // Goods coming back sit under Dispatch with the goods going out; the credit they
            // produce sits here. The document itself is listed in both places for that reason.
            { label: 'Customer returns', href: '/sales-returns', icon: 'sales-return', permissions: ['sales_return.view_any'] },
            { label: 'Supplier bills', href: '/supplier-bills', icon: 'bill', permissions: ['supplier_bill.view_any'] },
            { label: 'Payments', href: '/payments', icon: 'card', permissions: ['payment.view_any'] },
            { label: 'Expenses', href: '/expenses', icon: 'money', permissions: ['expense.view_any'] },
        ],
    },
    {
        label: 'Reports',
        /*
         * Each report also `requires` sight of the documents it is about. `report.view` alone
         * put Receivables and Payables in a store keeper's menu. "All reports" lists every one
         * of them for anybody who needs a report outside their own area.
         */
        items: [
            { label: 'All reports', href: '/reports', icon: 'reports', permissions: ['report.view_any', 'report.view'] },
            { label: 'Fulfilment', href: '/reports/fulfilment', icon: 'reports', permissions: ['report.view'], requires: ['sales_order.view_any'] },
            { label: 'Production', href: '/reports/production', icon: 'reports', permissions: ['report.view'], requires: ['job_card.view_any'] },
            { label: 'Stock', href: '/reports/stock', icon: 'reports', permissions: ['report.view'], requires: ['stock_lot.view_any'] },
            { label: 'Dispatch register', href: '/reports/dispatch', icon: 'reports', aliases: ['dispatch'], permissions: ['report.view'], requires: ['delivery_challan.view_any'] },
            { label: 'Receivables', href: '/reports/receivables', icon: 'reports', permissions: ['report.view'], requires: ['sales_invoice.view_any'] },
            { label: 'Payables', href: '/reports/payables', icon: 'reports', permissions: ['report.view'], requires: ['supplier_bill.view_any'] },
            { label: 'Purchases', href: '/reports/purchases', icon: 'reports', permissions: ['report.view'], requires: ['purchase_order.view_any'] },
            { label: 'NCR / CAPA', href: '/reports/ncr-capa', icon: 'reports', permissions: ['report.view'], requires: ['ncr.view_any'] },
        ],
    },
    {
        /*
         * Filled at runtime by `withSetupLists` from the registry the server shares
         * (`setupMenu`): one row per group — Factory, People, Commercial … — each opening to
         * its lists. Setup › Factory › Factory units, with no directory page in between.
         */
        label: 'Setup',
        items: [],
    },
    {
        label: 'Settings',
        items: [
            { label: 'Settings', href: '/admin/settings', icon: 'settings', aliases: ['configuration', 'company'], permissions: ['setting.view_any'] },
            { label: 'Number sequences', href: '/admin/number-sequences', icon: 'sequence', permissions: ['number_sequence.view_any'] },
            { label: 'Accounting periods', href: '/admin/accounting-periods', icon: 'calendar', aliases: ['month end', 'close period', 'period lock'], permissions: ['accounting_period.view_any'] },
        ],
    },
    {
        label: 'Access',
        items: [
            { label: 'Users', href: '/admin/users', icon: 'users', permissions: ['user.view_any'] },
            { label: 'Roles & permissions', href: '/admin/roles', icon: 'roles', permissions: ['role.view_any'] },
        ],
    },
    {
        label: 'Activity',
        items: [
            { label: 'Audit log', href: '/admin/audit-log', icon: 'audit', permissions: ['audit_log.view_any'] },
        ],
    },
];

/** The groups that configure the system rather than run it; listed after the day's work. */
export const SETUP_GROUPS = ['Setup', 'Settings', 'Access', 'Activity'];

/**
 * The tree with the Setup group filled from the server's list registry.
 *
 * @param {Array} sections  a navigation tree
 * @param {Array} menu  `[{ key, label, icon, lists: [{ slug, label, icon, href, permission }] }]`
 */
export function withSetupLists(sections, menu = []) {
    return sections.map((section) => {
        if (section.label !== 'Setup') {
            return section;
        }

        const items = (menu ?? []).map((group, index) => {
            const children = (group.lists ?? []).map((list) => ({
                label: list.label,
                href: list.href,
                icon: list.icon,
                permissions: [list.permission],
            }));

            return {
                label: group.label,
                href: children[0]?.href ?? '/setup',
                icon: group.icon,
                // Any list in the group shows the group; `visibleSections` then narrows the children.
                permissions: [...new Set(children.flatMap((child) => child.permissions))],
                aliases: index === 0 ? ['setup', 'configuration', 'reference data', 'lists'] : [],
                children,
            };
        });

        return { ...section, items };
    });
}

/**
 * The sections a user may actually open.
 *
 * Pure and exported so it can be tested: this ran inline in the layout, where a filter that
 * dropped every leaf item emptied the entire sidebar and no PHP test could see it — the pages
 * all still answered 200, they were simply unreachable.
 *
 * @param {Array} sections  a navigation tree
 * @param {Function} canAny  (...permissions) => boolean
 */
export function visibleSections(sections, canAny) {
    return sections
        .map((section) => ({
            ...section,
            items: section.items
                .filter((item) => canAny(...item.permissions))
                // `permissions` is any-of; `requires` is all-of, on top of it.
                .filter((item) => (item.requires ?? []).every((permission) => canAny(permission)))
                .map((item) => {
                    // A leaf has no children to narrow and must pass through untouched.
                    if (!item.children) {
                        return item;
                    }

                    const children = item.children.filter((child) => canAny(...child.permissions));

                    // Land on the first tab this user may actually open.
                    return { ...item, children, href: children[0]?.href ?? item.href };
                })
                // A hub whose every tab is out of reach is not a hub, it is a dead row.
                .filter((item) => !item.children || item.children.length > 0),
        }))
        .filter((section) => section.items.length > 0);
}
