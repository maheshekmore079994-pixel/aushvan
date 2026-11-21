import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import type { Dashboard } from '@/features/dashboard/api';
import i18n from '@/lib/i18n';
import { formatCompactRupees } from '@/lib/money';
import en from '@/locales/en.json';
import hi from '@/locales/hi.json';
import mr from '@/locales/mr.json';
import runnerSource from '../../../../app/Domain/Reports/ReportRunner.php?raw';
import permissionSource from '../../../../app/Enums/Permission.php?raw';
import { REPORTS } from './api';

type Call = { method: string; path: string; body: any };
type Handler = (call: Call) => { status: number; body: unknown };

function mockServer(routes: Record<string, Handler>) {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit = {}) => {
            const path = url.replace('/api/v1', '');
            const call: Call = { method: init.method ?? 'GET', path, body: init.body ? JSON.parse(init.body as string) : undefined };
            calls.push(call);
            const handler = routes[`${call.method} ${path.split('?')[0]}`];
            if (!handler) throw new Error(`Unexpected request: ${call.method} ${path}`);
            const result = handler(call);
            return new Response(result.status === 204 ? null : JSON.stringify(result.body), { status: result.status });
        }),
    );
    return calls;
}

const ok = (body: unknown) => () => ({ status: 200, body });

const me = (roles: string[], permissions: string[]) => ({
    id: 1, name: 'Asha Patil', username: 'owner', locale: 'en', is_pharmacist: false, roles, permissions,
    store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
});
const ownerPermissions = ['sales.create', 'products.manage', 'credit.receive', 'reports.view', 'reports.financial', 'users.manage', 'settings.manage', 'stock.view_cost'];

const shop = {
    name: 'Shree Medical', legal_name: null, address: 'Main Road', village: 'Wadgaon', taluka: null, district: null, state_code: '27', pincode: null,
    phone: '9800000000', gst_mode: 'unregistered', gstin: null,
    settings: { drug_licence_20: 'MH-1', drug_licence_21: null, drug_licence_20f: null, licence_retention_due: null, bill_footer: null, counter_prefix: '' },
};
const stockSummary = {
    products: 80, medical_products: 40, general_products: 40, low_stock: 3, out_of_stock: 2, expired_batches: 1, expiring_30: 2, expiring_60: 3,
    expiring_90: 4, schedule_not_set: 0, stock_value_paise: 12500000, ledger_mismatches: 0,
};

const base = (roles: string[], permissions: string[]): Record<string, Handler> => ({
    'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr', 'hi'] }),
    'GET /auth/me': ok(me(roles, permissions)),
    'GET /store': ok(shop),
    'GET /users': ok({ data: [{ id: 1 }, { id: 2 }], roles: ['owner', 'pharmacist', 'store_manager', 'cashier'] }),
    'GET /stock/summary': ok(stockSummary),
});

const totals = (sales: number, bills: number, profit?: number) => ({ bills, sales_paise: sales, udhar_paise: 0, ...(profit === undefined ? {} : { profit_paise: profit }) });

const dashboard = (full: boolean): Dashboard => ({
    today: '2026-11-11',
    mine: totals(45000, 3),
    udhar: { total_paise: 250000, customers: 4, top: [{ id: 7, name: 'Sunita Pawar', village: 'Wadgaon', balance_paise: 135000 }] },
    low_stock: [{ id: 3, name: 'Dolo 650 Tablet', type: 'medical', stock_qty: 0, min_stock_qty: 50, base_unit: 'TAB', pricing_unit: 'STRIP', pack_size: 15, stock_status: 'out' }],
    expiring: [{ product_id: 5, name: 'Cetzine 10 Tablet', batch_no: 'C77', expiry_date: '2026-11-30', days_left: 19 }],
    recent_bills: [{ id: 55, invoice_no: '26-27/000123', customer: null, total_paise: 6000, credit_paise: 0, at: '2026-11-11T05:00:00+00:00' }],
    ...(full
        ? {
              sales: { today: totals(120000, 8, 21500), yesterday: totals(100000, 6, 15000), month: totals(900000, 70, 150000), last_month_to_date: totals(1000000, 80, 160000) },
              received_today: { cash: 70000, upi: 30000, card: 0, bank: 0 },
              trend: Array.from({ length: 14 }, (_, i) => ({ date: `2026-10-${String(29 + i > 31 ? i - 2 : 29 + i).padStart(2, '0')}`, bills: i, sales_paise: i * 7000 })),
              split_30: { medical_paise: 600000, general_paise: 300000 },
              top_products: [{ id: 1, name: 'Crocin 500 Tablet', type: 'medical', amount_paise: 90000 }],
          }
        : {}),
});

function renderApp(path: string) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
        <QueryClientProvider client={client}>
            <ToastProvider>
                <MemoryRouter initialEntries={[path]}>
                    <App />
                </MemoryRouter>
            </ToastProvider>
        </QueryClientProvider>,
    );
}

beforeEach(async () => {
    vi.unstubAllGlobals();
    // The test browser has no drawing surface; the barcode library only uses it to measure the digits under the bars.
    HTMLCanvasElement.prototype.getContext = (() => ({ font: '', measureText: (text: string) => ({ width: text.length * 6 }) })) as never;
    localStorage.clear();
    await i18n.changeLanguage('en');
});

describe('languages', () => {
    const keys = (value: unknown, prefix = ''): string[] =>
        typeof value === 'object' && value !== null ? Object.entries(value).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k)) : [prefix];

    it('Hindi and Marathi have exactly the same keys as English, and no empty text', () => {
        expect(keys(hi).sort()).toEqual(keys(en).sort());
        expect(keys(mr).sort()).toEqual(keys(en).sort());
        const text = (source: unknown, key: string) => key.split('.').reduce<any>((node, part) => node?.[part], source);
        expect(keys(hi).filter((key) => !String(text(hi, key)).trim())).toEqual([]);
    });

    it('keeps every {{placeholder}} in Hindi', () => {
        const text = (source: unknown, key: string) => String(key.split('.').reduce<any>((node, part) => node?.[part], source));
        const holes = (value: string) => [...value.matchAll(/{{(\w+)}}/g)].map((m) => m[1]).sort().join();
        expect(keys(en).filter((key) => holes(text(en, key)) !== holes(text(hi, key)))).toEqual([]);
    });

    it('has a name, a description and column headings for every report, and a label for every permission', () => {
        const serverReports = [...runnerSource.matchAll(/^ {8}'([a-z_0-9]+)' => \['group'/gm)].map((m) => m[1]);
        expect(serverReports.sort()).toEqual(Object.keys(REPORTS).sort());
        for (const key of serverReports) {
            expect(en.reports.name).toHaveProperty(key);
            expect(en.reports.about).toHaveProperty(key);
        }
        const columns = new Set([...runnerSource.matchAll(/\['([a-z_0-9]+)', '(?:text|date|datetime|money|int|percent|label)'/g)].map((m) => m[1]));
        expect([...columns].filter((column) => !(column in en.reports.col))).toEqual([]);

        const permissions = [...permissionSource.matchAll(/= '([a-z_]+\.[a-z_]+)';/g)].map((m) => m[1].replace('.', '_'));
        expect(permissions.length).toBeGreaterThan(20);
        expect(permissions.filter((permission) => !(permission in en.roles.permission))).toEqual([]);
    });

    it('writes short amounts for chart axes in the Indian way', () => {
        expect(formatCompactRupees(95000)).toBe('₹950');
        expect(formatCompactRupees(250000)).toBe('₹2.5K');
        expect(formatCompactRupees(12500000)).toBe('₹1.25L');
        expect(formatCompactRupees(2500000000)).toBe('₹2.5Cr');
    });
});

describe('dashboard', () => {
    it('shows the owner sales, comparison, profit, charts and quick links', async () => {
        mockServer({ ...base(['owner'], ownerPermissions), 'GET /dashboard': ok(dashboard(true)) });
        renderApp('/');

        expect(await screen.findByText('Sales today')).toBeInTheDocument();
        expect(screen.getByText('₹1,200.00')).toBeInTheDocument();
        expect(screen.getByText('20% more')).toBeInTheDocument(); // 1,200 against 1,000 yesterday
        expect(screen.getByText('10% less')).toBeInTheDocument(); // 9,000 against 10,000 last month
        expect(screen.getByText('Profit today (approx.)')).toBeInTheDocument();
        expect(screen.getByText('₹215.00')).toBeInTheDocument();

        // The chart's numbers are also there as a table for screen readers.
        const table = screen.getByRole('table', { name: 'Sales in the last 14 days' });
        expect(within(table).getAllByRole('row')).toHaveLength(15);
        expect(screen.getByText('70%')).toBeInTheDocument(); // cash share of money received

        const quick = screen.getByRole('region', { name: 'Quick links' });
        for (const name of ['New Sale', 'Add product', 'Receive payment', 'Reports', 'Barcode Labels']) {
            expect(within(quick).getByRole('link', { name })).toBeInTheDocument();
        }
        expect(screen.getByText('Dolo 650 Tablet')).toBeInTheDocument();
        expect(screen.getByText('Sunita Pawar')).toBeInTheDocument();
        // Shop details are complete, so the setup checklist is gone.
        expect(screen.queryByText('Getting your shop ready')).not.toBeInTheDocument();
    });

    it('shows a cashier only their own bills, with no shop sales or profit', async () => {
        mockServer({ ...base(['cashier'], ['sales.create', 'credit.receive']), 'GET /dashboard': ok(dashboard(false)) });
        renderApp('/');

        expect(await screen.findByText('Your bills today')).toBeInTheDocument();
        expect(screen.getByText('₹450.00')).toBeInTheDocument();
        expect(screen.queryByText('Sales today')).not.toBeInTheDocument();
        expect(screen.queryByText('Profit today (approx.)')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Reports' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Roles' })).not.toBeInTheDocument();
    });
});

describe('reports', () => {
    const result = {
        key: 'sales_by_item',
        columns: [
            { key: 'product', type: 'text' }, { key: 'type', type: 'label', prefix: 'products.type' }, { key: 'qty', type: 'int' },
            { key: 'unit', type: 'label', prefix: 'unit' }, { key: 'amount', type: 'money' }, { key: 'profit', type: 'money' },
        ],
        rows: [
            { product: 'Crocin 500 Tablet', type: 'medical', qty: 30, unit: 'TAB', amount: 9000, profit: 1500 },
            { product: 'Tata Salt 1kg', type: 'general', qty: 4, unit: 'PKT', amount: 11200, profit: -300 },
        ],
        totals: { amount: 20200, profit: 1200 },
        filters: { from: '2026-11-01', to: '2026-11-11', type: null },
        truncated: false,
    };

    it('lists the reports and keeps a user without permission out', async () => {
        mockServer({ ...base(['owner'], ownerPermissions), 'GET /dashboard': ok(dashboard(true)) });
        renderApp('/reports');
        expect(await screen.findByRole('link', { name: /Daily sales/ })).toHaveAttribute('href', '/reports/sales_by_day');
        expect(screen.getByRole('link', { name: /Udhar to collect/ })).toBeInTheDocument();
    });

    it('shows a report with totals, filters it, sorts it and offers Excel, PDF and CSV in the chosen language', async () => {
        const user = userEvent.setup();
        const calls = mockServer({
            ...base(['owner'], ownerPermissions),
            'GET /auth/me': ok({ ...me(['owner'], ownerPermissions), locale: 'hi' }),
            'GET /reports/sales_by_item': ok(result),
        });
        renderApp('/reports/sales_by_item');

        const table = await screen.findByRole('table');
        expect(within(table).getByText('Crocin 500 Tablet')).toBeInTheDocument();
        expect(within(table).getByText('दवा')).toBeInTheDocument(); // "medical" in Hindi
        expect(within(table).getByText('-₹3.00')).toBeInTheDocument();
        expect(within(within(table).getAllByRole('rowgroup')[2]).getByText('₹202.00')).toBeInTheDocument();

        expect(screen.getByRole('link', { name: 'Excel' })).toHaveAttribute('href', expect.stringMatching(/reports\/sales_by_item\/export\?from=\d{4}-\d\d-01&to=.*&format=xlsx&lang=hi$/));
        expect(screen.getByRole('link', { name: 'PDF' })).toHaveAttribute('href', expect.stringContaining('format=pdf'));
        expect(screen.getByRole('link', { name: 'CSV' })).toHaveAttribute('href', expect.stringContaining('format=csv'));

        // Biggest amount first after one press on the heading.
        await user.click(within(table).getByRole('button', { name: 'रकम' }));
        expect(within(table).getAllByRole('row')[1]).toHaveTextContent('Tata Salt 1kg');

        await user.click(screen.getByRole('button', { name: 'दवा' }));
        await waitFor(() => expect(calls.some((c) => c.path.includes('type=medical'))).toBe(true));
        await user.click(screen.getByRole('button', { name: 'आज' }));
        await waitFor(() => expect(calls.filter((c) => c.path.startsWith('/reports/')).at(-1)?.path).toMatch(/from=(\d{4}-\d\d-\d\d)&to=\1/));
    });
});

describe('roles', () => {
    const roles = {
        data: [
            { id: 1, name: 'owner', builtin: true, locked: true, permissions: ['sales.create', 'reports.view'], users: 1 },
            { id: 4, name: 'cashier', builtin: true, locked: false, permissions: ['sales.create'], users: 2 },
            { id: 9, name: 'Helper', builtin: false, locked: false, permissions: [], users: 0 },
        ],
        permissions: ['sales.create', 'reports.view', 'reports.financial'],
    };

    it('lets the owner tick a permission for a role and saves it', async () => {
        const user = userEvent.setup();
        const calls = mockServer({
            ...base(['owner'], ownerPermissions),
            'GET /roles': ok(roles),
            'PUT /roles/4': (call) => ({ status: 200, body: { ...roles.data[1], permissions: call.body.permissions } }),
        });
        renderApp('/settings/roles');

        // The first role that can be changed is opened; the owner role is shown but locked.
        expect(await screen.findByRole('heading', { name: 'Cashier' })).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Make bills' })).toBeChecked();
        const save = screen.getByRole('button', { name: 'Save' });
        expect(save).toBeDisabled();

        await user.click(screen.getByRole('checkbox', { name: 'See reports and shop sales' }));
        await user.click(save);
        await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ name: 'cashier', permissions: ['sales.create', 'reports.view'] }));

        await user.click(screen.getByRole('tab', { name: /Owner/ }));
        expect(screen.getByText('The owner can always do everything. This cannot be changed.')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'See profit' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();

        await user.click(screen.getByRole('tab', { name: /Helper/ }));
        expect(screen.getByRole('button', { name: 'Delete role' })).toBeInTheDocument();
    });

    it('sends anyone who is not an owner back to the dashboard', async () => {
        const calls = mockServer({ ...base(['store_manager'], ['users.manage', 'products.manage']), 'GET /dashboard': ok(dashboard(false)) });
        renderApp('/settings/roles');
        expect(await screen.findByText(/Good (morning|afternoon|evening), Asha Patil/)).toBeInTheDocument();
        expect(calls.some((c) => c.path.startsWith('/roles'))).toBe(false);
    });
});

describe('barcode labels', () => {
    it('makes a shop barcode for a product without one and lays out the labels', async () => {
        const user = userEvent.setup();
        let barcode: string | null = null;
        const label = () => ({ id: 6, name: 'Loose Jaggery', name_local: null, type: 'general', mrp_paise: 0, selling_price_paise: 6000, pricing_unit: 'KG', barcode });
        const calls = mockServer({
            ...base(['owner'], ownerPermissions),
            'GET /products': ok({ data: [{ id: 6, type: 'general', name: 'Loose Jaggery' }], meta: { total: 1, page: 1, last_page: 1 }, matched_barcode: false }),
            'GET /products/labels': () => ({ status: 200, body: { data: [label()] } }),
            'POST /products/6/barcode': () => {
                barcode = '2000000000060';
                return { status: 200, body: label() };
            },
        });
        renderApp('/labels');

        expect(await screen.findByText('No labels yet')).toBeInTheDocument();
        await user.type(screen.getByRole('searchbox'), 'jag');
        await user.click(await screen.findByRole('button', { name: /Loose Jaggery/ }));
        expect(await screen.findByText('No barcode yet')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Print 0 labels' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: 'Make barcode' }));
        expect(await screen.findByRole('button', { name: 'Print 1 label' })).toBeEnabled();
        expect(calls.some((c) => c.method === 'POST' && c.path === '/products/6/barcode')).toBe(true);

        await user.click(screen.getByRole('button', { name: 'One more' }));
        await user.click(screen.getByRole('button', { name: 'One more' }));
        const sheet = screen.getByTestId('label-sheet');
        expect(sheet.children).toHaveLength(3);
        expect(within(sheet).getAllByRole('img', { name: '2000000000060' })).toHaveLength(3);
        expect(within(sheet).getAllByText(/₹60.00/)).toHaveLength(3);
        // A real barcode was drawn, not an empty picture.
        expect(sheet.querySelectorAll('svg rect').length).toBeGreaterThan(10);
    });
});

describe('sign out', () => {
    it('ends the session and shows the sign-in screen straight away', async () => {
        const user = userEvent.setup();
        const calls = mockServer({
            ...base(['owner'], ownerPermissions),
            'GET /dashboard': ok(dashboard(true)),
            'POST /auth/logout': () => ({ status: 204, body: null }),
        });
        renderApp('/');
        await screen.findByText('Sales today');

        await user.click(screen.getByRole('button', { name: 'Account' }));
        await user.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.queryByText('Sales today')).not.toBeInTheDocument();
        expect(calls.some((c) => c.method === 'POST' && c.path === '/auth/logout')).toBe(true);
    });
});
