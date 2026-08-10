/**
 * The navigation tree.
 *
 * Two rules, learned the hard way from a sidebar that reached 36 rows:
 *
 *  1. A screen someone opens fifty times a shift gets its own row. Everything else earns its
 *     place as a tab inside a hub — `children` on an item render as a tab strip on the page,
 *     not as more sidebar rows. The URLs are unchanged, so a deep link still lands.
 *  2. Configuration is not navigation. Setup, users, roles, settings and the audit log live in
 *     a separate shell entered from the footer, the way `socialx` handles its Settings and CMS
 *     modes — the main sidebar never shows admin noise.
 *
 * Each entry names the permissions that make it visible. Visibility is a courtesy; the route
 * middleware is the boundary (06-rbac §7).
 */
export const navigation = [
    {
        label: 'Overview',
        items: [
            { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', permissions: ['report.dashboard', 'report.view_any'] },
        ],
    },
    {
        // The commercial pipeline is sequential and opened constantly — inquiry, quote, order.
        // Hiding any of it behind a tab would cost a click on the busiest path in the system.
        label: 'Commercial',
        items: [
            { label: 'Inquiries', href: '/inquiries', icon: 'inbox', permissions: ['inquiry.view_any'] },
            { label: 'Quotations', href: '/quotations', icon: 'quote', permissions: ['quotation.view_any'] },
            { label: 'Sales orders', href: '/sales-orders', icon: 'order', permissions: ['sales_order.view_any'] },
            {
                label: 'Customers',
                href: '/customers',
                icon: 'customers',
                permissions: ['customer.view_any', 'price_list.view_any'],
                children: [
                    { label: 'Customers', href: '/customers', permissions: ['customer.view_any'] },
                    { label: 'Price lists', href: '/price-lists', permissions: ['price_list.view_any'] },
                ],
            },
        ],
    },
    {
        label: 'Engineering',
        items: [
            {
                label: 'Products',
                href: '/products',
                icon: 'product',
                permissions: ['product.view_any', 'artwork.view_any', 'routing.view_any', 'tool.view_any'],
                children: [
                    { label: 'Products', href: '/products', permissions: ['product.view_any'] },
                    { label: 'Artwork', href: '/artworks', permissions: ['artwork.view_any'] },
                    { label: 'Routings', href: '/routings', permissions: ['routing.view_any'] },
                    { label: 'Tools', href: '/tools', permissions: ['tool.view_any'] },
                ],
            },
        ],
    },
    {
        label: 'Supply',
        items: [
            { label: 'Requisitions', href: '/purchase-requisitions', icon: 'requisition', permissions: ['purchase_requisition.view_any'] },
            { label: 'Purchase orders', href: '/purchase-orders', icon: 'purchase-order', permissions: ['purchase_order.view_any'] },
            { label: 'Goods receipts', href: '/grns', icon: 'goods-receipt', permissions: ['grn.view_any'] },
            { label: 'Suppliers', href: '/suppliers', icon: 'supplier', permissions: ['supplier.view_any'] },
        ],
    },
    {
        label: 'Operations',
        items: [
            { label: 'Planning board', href: '/planning', icon: 'planning', permissions: ['production_plan.view_any'] },
            { label: 'Job cards', href: '/job-cards', icon: 'job-card', permissions: ['job_card.view_any'] },
            { label: 'MRP', href: '/mrp', icon: 'mrp', permissions: ['mrp.view_any', 'mrp.run'] },
            { label: 'Machines', href: '/machines', icon: 'machine', permissions: ['machine.view_any'] },
        ],
    },
    {
        label: 'Inventory',
        items: [
            {
                label: 'Inventory',
                href: '/stock',
                icon: 'stock',
                permissions: ['stock_lot.view_any', 'stock_issue.view_any', 'item.view_any'],
                children: [
                    { label: 'Stock enquiry', href: '/stock', permissions: ['stock_lot.view_any'] },
                    { label: 'Lots', href: '/lots', permissions: ['stock_lot.view_any'] },
                    { label: 'Material issues', href: '/material-issues', permissions: ['stock_issue.view_any'] },
                    { label: 'Items', href: '/items', permissions: ['item.view_any'] },
                ],
            },
        ],
    },
    {
        label: 'Quality',
        items: [
            {
                label: 'Quality',
                href: '/qc-inspections',
                icon: 'inspection',
                permissions: ['qc_inspection.view_any', 'test_report.view_any', 'lab_test.view_any', 'coc.view_any', 'certification.view_any'],
                children: [
                    { label: 'Inspections', href: '/qc-inspections', permissions: ['qc_inspection.view_any'] },
                    { label: 'Laboratory', href: '/lab', permissions: ['test_report.view_any', 'lab_test.view_any'] },
                    { label: 'Compliance & CoC', href: '/compliance', permissions: ['coc.view_any', 'certification.view_any'] },
                ],
            },
        ],
    },
    {
        label: 'Dispatch',
        items: [
            {
                label: 'Dispatch',
                href: '/packing-lists',
                icon: 'packing',
                permissions: ['packing_list.view_any', 'delivery_challan.view_any', 'trip.view_any'],
                children: [
                    { label: 'Packing lists', href: '/packing-lists', permissions: ['packing_list.view_any'] },
                    { label: 'Challans', href: '/delivery-challans', permissions: ['delivery_challan.view_any'] },
                    { label: 'Trips', href: '/trips', permissions: ['trip.view_any'] },
                ],
            },
        ],
    },
    {
        label: 'Money',
        items: [
            {
                label: 'Money',
                href: '/invoices',
                icon: 'invoice',
                permissions: ['sales_invoice.view_any', 'receipt.view_any'],
                children: [
                    { label: 'Invoices', href: '/invoices', permissions: ['sales_invoice.view_any'] },
                    { label: 'Receipts', href: '/receipts', permissions: ['receipt.view_any'] },
                ],
            },
        ],
    },
];

/**
 * The administration shell. Entered from the sidebar footer and exited explicitly, so these
 * six screens never compete with the shop floor for attention.
 */
export const adminNavigation = [
    {
        label: 'Configuration',
        items: [
            { label: 'Setup', href: '/setup', icon: 'sliders', permissions: ['reference_data.view_any'] },
            { label: 'Settings', href: '/admin/settings', icon: 'settings', permissions: ['setting.view_any'] },
            { label: 'Number sequences', href: '/admin/number-sequences', icon: 'sequence', permissions: ['number_sequence.view_any'] },
        ],
    },
    {
        label: 'Access',
        items: [
            { label: 'Users', href: '/admin/users', icon: 'users', permissions: ['user.view_any'] },
            { label: 'Roles & permissions', href: '/admin/roles', icon: 'roles', permissions: ['role.view_any'] },
            { label: 'Audit log', href: '/admin/audit-log', icon: 'audit', permissions: ['audit_log.view_any'] },
        ],
    },
];

/** URLs that belong to the administration shell rather than the working application. */
export const ADMIN_PREFIXES = ['/admin', '/setup'];

/**
 * The sections a user may actually open, with hub tabs filtered to match.
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
