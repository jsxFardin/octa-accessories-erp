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
            { label: 'Dashboard', href: '/dashboard', icon: '◈', permissions: ['report.dashboard', 'report.view_any'] },
        ],
    },
    {
        label: 'Commercial',
        items: [
            { label: 'Inquiries', href: '/inquiries', icon: '✉', permissions: ['inquiry.view_any'] },
            { label: 'Quotations', href: '/quotations', icon: '₵', permissions: ['quotation.view_any'] },
            { label: 'Sales orders', href: '/sales-orders', icon: '⌸', permissions: ['sales_order.view_any'] },
            { label: 'Customers', href: '/customers', icon: '☰', permissions: ['customer.view_any'] },
        ],
    },
    {
        label: 'Engineering',
        items: [
            { label: 'Products', href: '/products', icon: '◇', permissions: ['product.view_any'] },
            { label: 'Artwork', href: '/artworks', icon: '✎', permissions: ['artwork.view_any'] },
            { label: 'Routings', href: '/routings', icon: '⇉', permissions: ['routing.view_any'] },
            { label: 'Tools', href: '/tools', icon: '⚒', permissions: ['tool.view_any'] },
        ],
    },
    {
        label: 'Supply',
        items: [
            { label: 'Requisitions', href: '/purchase-requisitions', icon: '↥', permissions: ['purchase_requisition.view_any'] },
            { label: 'Purchase orders', href: '/purchase-orders', icon: '⇱', permissions: ['purchase_order.view_any'] },
            { label: 'Goods receipts', href: '/grns', icon: '⇲', permissions: ['grn.view_any'] },
            { label: 'Suppliers', href: '/suppliers', icon: '⛬', permissions: ['supplier.view_any'] },
        ],
    },
    {
        label: 'Inventory',
        items: [
            { label: 'Stock enquiry', href: '/stock', icon: '▦', permissions: ['stock_lot.view_any'] },
            { label: 'Lots', href: '/lots', icon: '◫', permissions: ['stock_lot.view_any'] },
            { label: 'Material issues', href: '/material-issues', icon: '↧', permissions: ['stock_issue.view_any'] },
            { label: 'Items', href: '/items', icon: '⌗', permissions: ['item.view_any'] },
        ],
    },
    {
        label: 'Operations',
        items: [
            { label: 'Planning board', href: '/planning', icon: '▤', permissions: ['production_plan.view_any'] },
            { label: 'MRP', href: '/mrp', icon: '∑', permissions: ['mrp.view_any', 'mrp.run'] },
            { label: 'Job cards', href: '/job-cards', icon: '▣', permissions: ['job_card.view_any'] },
            { label: 'Machines', href: '/machines', icon: '⚙', permissions: ['machine.view_any'] },
        ],
    },
    {
        label: 'Assurance',
        items: [
            { label: 'QC inspections', href: '/qc-inspections', icon: '✓', permissions: ['qc_inspection.view_any'] },
            { label: 'Laboratory', href: '/lab', icon: '⚗', permissions: ['test_report.view_any', 'lab_test.view_any'] },
            { label: 'Compliance & CoC', href: '/compliance', icon: '⛨', permissions: ['coc.view_any', 'certification.view_any'] },
        ],
    },
    {
        label: 'Fulfilment',
        items: [
            { label: 'Packing lists', href: '/packing-lists', icon: '▢', permissions: ['packing_list.view_any'] },
            { label: 'Challans', href: '/delivery-challans', icon: '⇥', permissions: ['delivery_challan.view_any'] },
            { label: 'Trips', href: '/trips', icon: '⛟', permissions: ['trip.view_any'] },
        ],
    },
    {
        label: 'Money',
        items: [
            { label: 'Invoices', href: '/invoices', icon: '₮', permissions: ['sales_invoice.view_any'] },
            { label: 'Receipts', href: '/receipts', icon: '↺', permissions: ['receipt.view_any'] },
        ],
    },
    {
        label: 'Administration',
        items: [
            { label: 'Users & roles', href: '/admin/users', icon: '☗', permissions: ['user.view_any'] },
            { label: 'Settings', href: '/admin/settings', icon: '⚑', permissions: ['setting.view_any'] },
            { label: 'Number sequences', href: '/admin/number-sequences', icon: '#', permissions: ['number_sequence.view_any'] },
            { label: 'Audit log', href: '/admin/audit-log', icon: '☱', permissions: ['audit_log.view_any'] },
        ],
    },
];
