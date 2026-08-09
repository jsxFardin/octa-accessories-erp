/**
 * The navigation tree, grouped by the bounded contexts in 01-domain-model §1 rather than by
 * database table — a merchandiser thinks in "Commercial", not in "sales_order_lines".
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
        label: 'Commercial',
        items: [
            { label: 'Inquiries', href: '/inquiries', icon: 'inbox', permissions: ['inquiry.view_any'] },
            { label: 'Quotations', href: '/quotations', icon: 'quote', permissions: ['quotation.view_any'] },
            { label: 'Sales orders', href: '/sales-orders', icon: 'order', permissions: ['sales_order.view_any'] },
            { label: 'Customers', href: '/customers', icon: 'customers', permissions: ['customer.view_any'] },
            { label: 'Price lists', href: '/price-lists', icon: 'card', permissions: ['price_list.view_any'] },
        ],
    },
    {
        label: 'Engineering',
        items: [
            { label: 'Products', href: '/products', icon: 'product', permissions: ['product.view_any'] },
            { label: 'Artwork', href: '/artworks', icon: 'artwork', permissions: ['artwork.view_any'] },
            { label: 'Routings', href: '/routings', icon: 'routing', permissions: ['routing.view_any'] },
            { label: 'Tools', href: '/tools', icon: 'tool', permissions: ['tool.view_any'] },
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
        label: 'Inventory',
        items: [
            { label: 'Stock enquiry', href: '/stock', icon: 'stock', permissions: ['stock_lot.view_any'] },
            { label: 'Lots', href: '/lots', icon: 'lot', permissions: ['stock_lot.view_any'] },
            { label: 'Material issues', href: '/material-issues', icon: 'issue', permissions: ['stock_issue.view_any'] },
            { label: 'Items', href: '/items', icon: 'item', permissions: ['item.view_any'] },
        ],
    },
    {
        label: 'Operations',
        items: [
            { label: 'Planning board', href: '/planning', icon: 'planning', permissions: ['production_plan.view_any'] },
            { label: 'MRP', href: '/mrp', icon: 'mrp', permissions: ['mrp.view_any', 'mrp.run'] },
            { label: 'Job cards', href: '/job-cards', icon: 'job-card', permissions: ['job_card.view_any'] },
            { label: 'Machines', href: '/machines', icon: 'machine', permissions: ['machine.view_any'] },
        ],
    },
    {
        label: 'Assurance',
        items: [
            { label: 'QC inspections', href: '/qc-inspections', icon: 'inspection', permissions: ['qc_inspection.view_any'] },
            { label: 'Laboratory', href: '/lab', icon: 'lab', permissions: ['test_report.view_any', 'lab_test.view_any'] },
            { label: 'Compliance & CoC', href: '/compliance', icon: 'compliance', permissions: ['coc.view_any', 'certification.view_any'] },
        ],
    },
    {
        label: 'Fulfilment',
        items: [
            { label: 'Packing lists', href: '/packing-lists', icon: 'packing', permissions: ['packing_list.view_any'] },
            { label: 'Challans', href: '/delivery-challans', icon: 'challan', permissions: ['delivery_challan.view_any'] },
            { label: 'Trips', href: '/trips', icon: 'trip', permissions: ['trip.view_any'] },
        ],
    },
    {
        label: 'Money',
        items: [
            { label: 'Invoices', href: '/invoices', icon: 'invoice', permissions: ['sales_invoice.view_any'] },
            { label: 'Receipts', href: '/receipts', icon: 'receipt', permissions: ['receipt.view_any'] },
        ],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Users', href: '/admin/users', icon: 'users', permissions: ['user.view_any'] },
            { label: 'Roles & permissions', href: '/admin/roles', icon: 'roles', permissions: ['role.view_any'] },
            { label: 'Setup', href: '/setup', icon: 'sliders', permissions: ['reference_data.view_any'] },
            { label: 'Organisation', href: '/admin/organisation', icon: 'building', permissions: ['setting.view_any'] },
            { label: 'Settings', href: '/admin/settings', icon: 'settings', permissions: ['setting.view_any'] },
            { label: 'Number sequences', href: '/admin/number-sequences', icon: 'sequence', permissions: ['number_sequence.view_any'] },
            { label: 'Audit log', href: '/admin/audit-log', icon: 'audit', permissions: ['audit_log.view_any'] },
        ],
    },
];
