import { describe, expect, it } from 'vitest';
import { ADMIN_PREFIXES, adminNavigation, navigation, visibleSections } from '../../resources/js/navigation.js';

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
            'Floor',
            'Products',
            'Inventory',
            'Quality',
            'Dispatch',
            'Money',
            'Reports',
        ]);
        expect(visibleSections(navigation, all).find((section) => section.label === 'Overview')?.heading).toBe(false);
    });

    it('puts every screen on its own row, not behind a folder or a tab strip', () => {
        expect(itemsOf('Sales')).toEqual(['Inquiries', 'Quotations', 'Sales orders', 'Customers', 'Price lists']);
        expect(itemsOf('Floor')).toContain('Material plan');
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

    it('shows every administration screen inside the shell', () => {
        expect(rows(visibleSections(adminNavigation, all))).toEqual([
            'Lists',
            'Settings',
            'Number sequences',
            'Users',
            'Roles & permissions',
            'Audit log',
        ]);
    });

    it('hides everything from a user with no permissions', () => {
        expect(visibleSections(navigation, none)).toEqual([]);
        expect(visibleSections(adminNavigation, none)).toEqual([]);
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

    it('keeps administration out of the working application', () => {
        const mainHrefs = navigation.flatMap((s) => s.items).map((i) => i.href);

        for (const href of mainHrefs) {
            expect(ADMIN_PREFIXES.some((prefix) => href.startsWith(prefix))).toBe(false);
        }
    });

    it('opens Access without a heading, and keeps old names as search aliases', () => {
        expect(adminNavigation.every((section) => section.heading === false)).toBe(true);

        const items = [...navigation, ...adminNavigation].flatMap((section) => section.items);
        const byHref = Object.fromEntries(items.map((item) => [item.href, item]));

        expect(byHref['/mrp'].aliases).toContain('mrp');
        expect(byHref['/stock'].aliases).toContain('stock enquiry');
        expect(byHref['/items'].aliases).toContain('items');
        expect(byHref['/delivery-challans'].aliases).toContain('challans');
        expect(byHref['/setup'].aliases).toContain('setup');
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
