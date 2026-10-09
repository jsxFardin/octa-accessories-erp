import { describe, expect, it } from 'vitest';
import { SETUP_GROUPS, navigation, visibleSections, withSetupLists } from '../../resources/js/navigation.js';

/** What the server shares as `setupMenu`: the reference lists by group. */
const menu = [
    {
        key: 'organisation',
        label: 'Factory',
        icon: 'building',
        lists: [
            { slug: 'factory-units', label: 'Factory units', icon: 'building', href: '/setup/factory-units', permission: 'reference_data.view_any' },
            { slug: 'departments', label: 'Departments', icon: 'users', href: '/setup/departments', permission: 'reference_data.view_any' },
            { slug: 'shifts', label: 'Shifts', icon: 'planning', href: '/setup/shifts', permission: 'reference_data.view_any' },
        ],
    },
    {
        key: 'people',
        label: 'People',
        icon: 'users',
        lists: [{ slug: 'employees', label: 'Employees', icon: 'users', href: '/setup/employees', permission: 'employee.view_any' }],
    },
];

const tree = withSetupLists(navigation, menu);

const all = () => true;
const none = () => false;
const only = (...granted) => (...wanted) => wanted.some((p) => granted.includes(p));

function rows(sections) {
    return sections.flatMap((section) => section.items.map((item) => item.label));
}

function itemsOf(label) {
    return navigation.find((section) => section.label === label)?.items.map((item) => item.label);
}

describe('sidebar visibility', () => {
    it('renders leaf items, not just hubs', () => {
        // The regression this exists for: a filter meant to drop empty hubs dropped every leaf
        // item too, so the whole sidebar rendered blank while every page still answered 200.
        const labels = rows(visibleSections(tree, all));

        expect(labels).toContain('Inquiries');
        expect(labels).toContain('Suppliers');
        expect(labels).toContain('Job cards');
        expect(labels).toContain('Products');
        expect(labels).toContain('Artwork');
        expect(labels).toContain('On-hand');
        expect(labels).toContain('Inspections');
        expect(labels).toContain('Packing lists');
        expect(labels).toContain('Invoices');
        expect(labels).toContain('All reports');
        expect(labels.length).toBeGreaterThan(20);
    });

    it('groups the factory sequence into collapsible sections', () => {
        const labels = visibleSections(tree, all).map((section) => section.label);

        expect(labels).toEqual([
            'Overview',
            'Sales',
            'Buying',
            'Production',
            'Products',
            'Inventory',
            'Quality',
            'Dispatch',
            'Money',
            'Reports',
            'Setup',
            'Settings',
            'Access',
            'Activity',
        ]);
        expect(visibleSections(tree, all).find((section) => section.label === 'Overview')?.heading).toBe(false);
    });

    it('puts every screen on its own row, not behind a folder or a tab strip', () => {
        expect(itemsOf('Sales')).toEqual(['Inquiries', 'Quotations', 'Sales orders', 'Customers', 'Price lists']);
        expect(itemsOf('Production')).toContain('Material plan');
        expect(itemsOf('Products')).toEqual(['Products', 'Artwork', 'BOMs', 'Routings', 'Tools']);
        expect(itemsOf('Inventory')).toContain('On-hand');
        expect(itemsOf('Inventory')).toContain('Lots');
        expect(itemsOf('Inventory')).toContain('Materials');
        expect(itemsOf('Quality')).toEqual(['Inspections', 'NCRs', 'Laboratory', 'Compliance & CoC']);
        expect(itemsOf('Dispatch')).toEqual(['Packing lists', 'Delivery notes', 'Trips']);
        expect(itemsOf('Money')).toContain('Supplier bills');
        expect(itemsOf('Buying')).toContain('Import shipments');
        expect(itemsOf('Buying')).toContain('Letters of credit');

        // Setup is the one place a row opens to a second level: a list group to its lists.
        for (const section of navigation) {
            for (const item of section.items) {
                expect(item.children).toBeUndefined();
                expect(item.sidebar).toBeUndefined();
            }
        }
    });

    it('builds Setup from the shared list registry: Setup › Factory › Factory units', () => {
        const setup = tree.find((section) => section.label === 'Setup');

        expect(setup.items.map((item) => item.label)).toEqual(['Factory', 'People']);
        expect(setup.items[0].href).toBe('/setup/factory-units');
        expect(setup.items[0].children.map((child) => child.label)).toEqual(['Factory units', 'Departments', 'Shifts']);
        expect(setup.items[0].children[1].href).toBe('/setup/departments');
        expect(setup.items[0].aliases).toContain('setup');
        // Nothing else in the tree is touched, and the static tree has no directory row.
        expect(navigation.find((section) => section.label === 'Setup').items).toEqual([]);
        expect(tree.flatMap((section) => section.items).map((item) => item.href)).not.toContain('/setup');
    });

    it('shows a list group only when the user may read a list inside it', () => {
        // A planner reads the factory lists but not employees: People goes, Factory stays.
        const setup = visibleSections(tree, only('reference_data.view_any')).find((section) => section.label === 'Setup');

        expect(setup.items.map((item) => item.label)).toEqual(['Factory']);
        expect(visibleSections(tree, none)).toEqual([]);
    });

    it('lists the shop-floor terminal once, under one name', () => {
        // The regression this exists for: '/floor' was listed in the Production group as
        // 'Floor terminal' and again in the sidebar footer as 'Shop floor' — two rows, two
        // labels, two icons for one screen, which read as two different features.
        const floorRows = navigation.flatMap((s) => s.items).filter((i) => i.href === '/floor');

        expect(floorRows).toHaveLength(1);
        expect(floorRows[0].label).toBe('Shop floor terminal');
        expect(floorRows[0].external).toBe(true);
    });

    it('lists setup, access and activity as groups after the day\'s work', () => {
        // Configuration used to be a separate shell entered from the footer and left through
        // an "Exit configuration" header. It is three ordinary groups now, last in the tree.
        expect(navigation.slice(-4).map((section) => section.label)).toEqual(SETUP_GROUPS);
        expect(itemsOf('Settings')).toEqual(['Settings', 'Number sequences', 'Accounting periods']);
        expect(itemsOf('Access')).toEqual(['Users', 'Roles & permissions']);
        expect(itemsOf('Activity')).toEqual(['Audit log']);
        expect(navigation.every((section) => section.open !== true)).toBe(true);
    });

    it('hides everything from a user with no permissions', () => {
        expect(visibleSections(navigation, none)).toEqual([]);
    });

    it('drops a group whose every screen is out of reach', () => {
        const sections = visibleSections(navigation, only('sales_order.view_any'));

        expect(rows(sections)).toEqual(['Sales orders']);
    });

    it('shows only the inventory screens the user may open', () => {
        // Someone who may issue material but not read stock balances should not land on a 403.
        const sections = visibleSections(navigation, only('stock_issue.view_any'));
        const inventory = sections.find((section) => section.label === 'Inventory');

        expect(inventory.items.map((item) => item.label)).toEqual(['Material issues']);
        expect(inventory.items[0].href).toBe('/material-issues');
    });

    it('keeps every screen reachable by its own URL', () => {
        for (const section of tree) {
            for (const item of section.items.flatMap((entry) => [entry, ...(entry.children ?? [])])) {
                expect(item.href.startsWith('/')).toBe(true);
                expect(item.permissions.length).toBeGreaterThan(0);
                expect(item.icon).toBeTruthy();
            }
        }
    });

    it('keeps old names as search aliases', () => {
        const items = navigation.flatMap((section) => section.items);
        const byHref = Object.fromEntries(items.map((item) => [item.href, item]));

        expect(byHref['/mrp'].aliases).toContain('mrp');
        expect(byHref['/stock'].aliases).toContain('stock enquiry');
        expect(byHref['/items'].aliases).toContain('items');
        expect(byHref['/delivery-challans'].aliases).toContain('challans');
        expect(byHref['/admin/settings'].aliases).toContain('configuration');
        expect(byHref['/boms']).toBeTruthy();
    });
});

describe('list URLs', () => {
    const hrefs = tree.flatMap((section) => section.items.flatMap((item) => [item, ...(item.children ?? [])])).map((item) => item.href);

    it('knows a list URL from a form URL', () => {
        expect(hrefs).toContain('/products');
        expect(hrefs).not.toContain('/products/create');
    });

    it('gives every screen a URL that is a list, never a detail route', () => {
        for (const href of hrefs) {
            expect(href).not.toMatch(/\/(create|edit)$/);
            expect(href).not.toMatch(/\{|\}|:/);
        }
    });
});

// UX audit M-02: `report.view` alone put Receivables and Payables in a store keeper's menu.
describe('report links', () => {
    function reportLabels(...granted) {
        const section = visibleSections(navigation, only(...granted)).find((s) => s.label === 'Reports');

        return (section?.items ?? []).map((item) => item.label);
    }

    it('show a report only to someone who can also see what it is about', () => {
        const labels = reportLabels('report.view', 'stock_lot.view_any', 'purchase_order.view_any');

        expect(labels).toEqual(['All reports', 'Stock', 'Purchases']);
    });

    it('keep All reports for anyone who may read reports', () => {
        expect(reportLabels('report.view')).toEqual(['All reports']);
    });

    it('show nothing without the report permission, whatever else is held', () => {
        expect(reportLabels('stock_lot.view_any', 'sales_invoice.view_any')).toEqual([]);
    });
});
