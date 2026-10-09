import { describe, expect, it } from 'vitest';
import { SETUP_GROUPS, navigation, visibleSections } from '../../resources/js/navigation.js';

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
        const labels = rows(visibleSections(navigation, all));

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
        const labels = visibleSections(navigation, all).map((section) => section.label);

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
            'Access',
            'Activity',
        ]);
        expect(visibleSections(navigation, all).find((section) => section.label === 'Overview')?.heading).toBe(false);
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

        for (const section of navigation) {
            for (const item of section.items) {
                expect(item.children).toBeUndefined();
                expect(item.sidebar).toBeUndefined();
            }
        }
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
        expect(navigation.slice(-3).map((section) => section.label)).toEqual(SETUP_GROUPS);
        expect(itemsOf('Setup')).toEqual(['Lists', 'Settings', 'Number sequences']);
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
        for (const section of navigation) {
            for (const item of section.items) {
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
        expect(byHref['/setup'].aliases).toContain('setup');
        expect(byHref['/setup'].aliases).toContain('configuration');
        expect(byHref['/boms']).toBeTruthy();
    });
});

describe('list URLs', () => {
    const hrefs = navigation.flatMap((section) => section.items).map((item) => item.href);

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
