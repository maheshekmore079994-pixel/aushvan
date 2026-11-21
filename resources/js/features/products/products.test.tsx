import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import i18n from '@/lib/i18n';
import en from '@/locales/en.json';
import type { Batch, CatalogOptions, ProductDetail, ProductRow } from './api';

type Call = { method: string; path: string; body: any; headers: Record<string, string> };
type Handler = (call: Call) => { status: number; body: unknown };

/** Fake server. Keys are "METHOD /path" with the query string removed. */
function mockServer(routes: Record<string, Handler>) {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit = {}) => {
            const [path] = url.replace('/api/v1', '').split('?');
            const call: Call = {
                method: init.method ?? 'GET',
                path: url.replace('/api/v1', ''),
                body: init.body ? JSON.parse(init.body as string) : undefined,
                headers: (init.headers ?? {}) as Record<string, string>,
            };
            calls.push(call);
            const handler = routes[`${call.method} ${path}`];
            if (!handler) throw new Error(`Unexpected request: ${call.method} ${path}`);
            const result = handler(call);
            return new Response(JSON.stringify(result.body), { status: result.status });
        }),
    );
    return calls;
}

const ok = (body: unknown) => (_call?: Call) => ({ status: 200, body });

const me = (permissions: string[]) => ({
    id: 1, name: 'Asha', username: 'owner', locale: 'en', is_pharmacist: false, roles: ['owner'], permissions,
    store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
});

const options: CatalogOptions = {
    units: [
        { id: 1, code: 'PCS', name: 'Piece', name_local: 'नग' },
        { id: 2, code: 'TAB', name: 'Tablet', name_local: 'गोळी' },
        { id: 4, code: 'STRIP', name: 'Strip', name_local: 'स्ट्रिप' },
    ],
    tax_rates: [
        { id: 1, name: 'GST 0% (Nil)', rate_percent: '0.00', kind: 'nil' },
        { id: 2, name: 'GST 5%', rate_percent: '5.00', kind: 'taxable' },
    ],
    drug_schedules: [
        { id: 1, code: 'NONE', name: 'Not a scheduled drug', requires_prescription: false, warning_text: null },
        { id: 2, code: 'H', name: 'Schedule H', requires_prescription: true, warning_text: 'Sell only on prescription.' },
    ],
    categories: [{ id: 1, name: 'Tablets & Capsules', name_local: null, scope: 'medical' }],
    manufacturers: ['GSK'],
    dosage_forms: ['tablet', 'syrup'],
};

const row = (overrides: Partial<ProductRow>): ProductRow => ({
    id: 1, type: 'medical', name: 'Crocin 500 Tablet', name_local: null, sku: 'M00001', brand: null, category: null,
    generic_name: 'Paracetamol', strength: '500 mg', schedule: 'NONE', schedule_set: true, mrp_paise: 3000,
    selling_price_paise: 3000, base_unit: 'TAB', pricing_unit: 'STRIP', pack_size: 10, rack_location: 'A1', is_active: true,
    min_stock_qty: 50, stock_qty: 125, expired_qty: 0, unsellable_qty: 0, nearest_expiry: '2028-05-31', stock_status: 'ok',
    ...overrides,
});

const batch = (overrides: Partial<Batch>): Batch => ({
    id: 7, batch_no: 'RQ3549', expiry_date: '2028-05-31', received_date: '2026-11-11', mrp_paise: 3000, selling_price_paise: 3000,
    qty_sellable: 125, qty_unsellable: 0, days_to_expiry: 567, expiry_status: 'ok', cost_paise: 2340, value_paise: 29250,
    ...overrides,
});

const detail = (overrides: Partial<ProductDetail> = {}): ProductDetail => ({
    ...row({}),
    category_id: 1, manufacturer: 'GSK', hsn_code: '3004', tax_rate_id: 2, tax_rate: 'GST 5%', base_unit_id: 2, pack_unit_id: 4,
    allow_loose_sale: true, min_stock: 5, tracks_batch: true, tracks_expiry: true, is_quick_item: false, version: 1,
    has_stock_history: true, barcodes: [],
    medicine: { generic_name: 'Paracetamol', composition_text: null, strength: '500 mg', dosage_form: 'tablet', drug_schedule_id: 1, is_ndps: false, storage_instructions: null },
    batches: [batch({})],
    ...overrides,
});

const paged = <T,>(data: T[]) => ({ data, meta: { total: data.length, page: 1, last_page: 1 }, matched_barcode: false });

function base(permissions: string[]): Record<string, Handler> {
    return {
        'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr'] }),
        'GET /auth/me': ok(me(permissions)),
        'GET /catalog/options': ok(options),
    };
}

function renderApp(path: string) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
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
    await i18n.changeLanguage('en');
    window.scrollTo = vi.fn();
});

describe('every text used on a screen exists', () => {
    const sources = import.meta.glob('/resources/js/**/*.tsx', { query: '?raw', import: 'default', eager: true }) as Record<string, string>;

    const exists = (key: string): boolean => {
        const lookup = (k: string) => k.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], en);
        return lookup(key) !== undefined || lookup(`${key}_other`) !== undefined;
    };

    it('has an English text for every fixed key passed to t()', () => {
        const missing = new Set<string>();
        for (const [file, source] of Object.entries(sources)) {
            if (file.includes('.test.')) continue;
            for (const match of source.matchAll(/\bt\(\s*'([a-z_]+(?:\.[A-Za-z0-9_]+)+)'/g)) {
                if (!exists(match[1])) missing.add(`${match[1]}  (${file})`);
            }
        }
        expect([...missing]).toEqual([]);
    });
});

describe('product list', () => {
    it('shows stock in packs, status badges and expired stock, and searches as you type', async () => {
        const calls = mockServer({
            ...base(['products.manage']),
            'GET /products': (call) =>
                ok(
                    paged(
                        call.path.includes('search=dolo')
                            ? [row({ id: 2, name: 'Dolo 650 Tablet', stock_qty: 0, stock_status: 'out', nearest_expiry: null })]
                            : [row({}), row({ id: 3, name: 'Cetzine 10 Tablet', stock_qty: 20, stock_status: 'low', expired_qty: 30, schedule: 'H' })],
                    ),
                )(call),
        });
        const user = userEvent.setup();
        renderApp('/products');

        const crocin = (await screen.findByRole('link', { name: 'Crocin 500 Tablet' })).closest('tr')!;
        expect(within(crocin).getByText('12 strips + 5 tablets')).toBeInTheDocument();
        expect(within(crocin).getByText('In stock')).toBeInTheDocument();
        expect(within(crocin).getByText('₹30.00')).toBeInTheDocument();
        expect(within(crocin).getByText('May 2028')).toBeInTheDocument();

        const cetzine = screen.getByRole('link', { name: 'Cetzine 10 Tablet' }).closest('tr')!;
        expect(within(cetzine).getByText('Low')).toBeInTheDocument();
        expect(within(cetzine).getByText('Expired on shelf: 3 strips')).toBeInTheDocument();
        expect(within(cetzine).getByText('Schedule H')).toBeInTheDocument();

        await user.type(screen.getByRole('searchbox'), 'dolo');
        expect(await screen.findByRole('link', { name: 'Dolo 650 Tablet' })).toBeInTheDocument();
        expect(screen.getByText('Out of stock', { selector: 'span' })).toBeInTheDocument();
        expect(calls.some((c) => c.path.includes('search=dolo'))).toBe(true);
    });

    it('fixes the type on the Medical Stock screen and filters by low stock', async () => {
        const calls = mockServer({ ...base([]), 'GET /products': ok(paged([row({})])) });
        const user = userEvent.setup();
        renderApp('/stock/medical');

        await screen.findByRole('link', { name: 'Crocin 500 Tablet' });
        expect(screen.queryByRole('button', { name: 'General item' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add product' })).not.toBeInTheDocument(); // no permission

        await user.click(screen.getByRole('button', { name: 'Low' }));
        await waitFor(() => expect(calls.some((c) => c.path.includes('type=medical') && c.path.includes('stock=low'))).toBe(true));
    });
});

describe('product form', () => {
    it('saves a medicine, converting rupees to paise, and warns before a duplicate is saved', async () => {
        let attempts = 0;
        const calls = mockServer({
            ...base(['products.manage']),
            'GET /products/similar': ok({ data: [] }),
            'POST /products': () =>
                ++attempts === 1
                    ? { status: 409, body: { code: 'possible_duplicate', message: 'x', duplicates: [{ id: 9, name: 'Crocin 500 Tablet', sku: 'M00009' }] } }
                    : { status: 201, body: detail({ id: 10 }) },
            'GET /products/10': ok(detail({ id: 10 })),
            'GET /products/10/movements': ok(paged([])),
        });
        const user = userEvent.setup();
        renderApp('/products/new');

        await user.type(await screen.findByLabelText('Name'), 'Crocin 500 Tablet');
        await user.type(screen.getByLabelText(/Salt/), 'Paracetamol');
        await user.clear(screen.getByLabelText(/1 Strip = how many Tablet/));
        await user.type(screen.getByLabelText(/1 Strip = how many Tablet/), '15');
        await user.type(screen.getByLabelText(/MRP per Strip/), '30');
        await user.selectOptions(screen.getByLabelText('Drug schedule'), '2');
        expect(screen.getByText('Sell only on prescription.')).toBeInTheDocument();

        await user.click(screen.getAllByRole('button', { name: 'Save' })[0]);

        expect(await screen.findByText('A product with this name already exists:')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Crocin 500 Tablet' })).toHaveAttribute('href', '/products/9');

        await user.click(screen.getByRole('button', { name: /save anyway/ }));
        expect(await screen.findByText('Stock by batch')).toBeInTheDocument(); // moved to the product page

        const posts = calls.filter((c) => c.method === 'POST' && c.path === '/products');
        expect(posts[0].body).toMatchObject({
            type: 'medical', name: 'Crocin 500 Tablet', base_unit_id: 2, pack_unit_id: 4, pack_size: 15, mrp_paise: 3000,
            selling_price_paise: 3000, // empty selling price means "sell at MRP"
            tax_rate_id: 2, confirm_duplicate: false, medicine: { generic_name: 'Paracetamol', drug_schedule_id: 2 },
        });
        expect(posts[1].body.confirm_duplicate).toBe(true);
        expect(posts[1].headers['Idempotency-Key']).toBe(posts[0].headers['Idempotency-Key']);
    });

    it('stops a selling price above MRP before anything is sent', async () => {
        const calls = mockServer({ ...base(['products.manage']), 'GET /products/similar': ok({ data: [] }) });
        const user = userEvent.setup();
        renderApp('/products/new');

        await user.type(await screen.findByLabelText('Name'), 'Test');
        await user.type(screen.getByLabelText(/MRP per Strip/), '30');
        await user.type(screen.getByLabelText(/Selling price per Strip/), '31.50');
        await user.click(screen.getAllByRole('button', { name: 'Save' })[0]);

        expect(await screen.findByText('Selling price cannot be more than MRP.')).toBeInTheDocument();
        expect(calls.some((c) => c.method === 'POST')).toBe(false);
    });

    it('locks type and units when editing a product that has stock', async () => {
        mockServer({ ...base(['products.manage']), 'GET /products/1': ok(detail()), 'GET /products/similar': ok({ data: [] }) });
        renderApp('/products/1/edit');

        expect(await screen.findByLabelText('Name')).toHaveValue('Crocin 500 Tablet');
        expect(screen.getByRole('button', { name: 'General item' })).toBeDisabled();
        expect(screen.getByLabelText('Smallest unit')).toBeDisabled();
        expect(screen.getByLabelText(/MRP per Strip/)).toHaveValue('30.00');
        expect(screen.getByText(/cannot be changed because stock has been entered/)).toBeInTheDocument();
    });
});

describe('product page and stock actions', () => {
    const routes = (permissions: string[], product = detail()): Record<string, Handler> => ({
        ...base(permissions),
        'GET /products/1': ok(product),
        'GET /products/1/movements': ok(
            paged([{ id: 1, at: '2026-11-11T08:00:00+00:00', type: 'opening', bucket: 'sellable', qty_delta: 125, balance_after: 125, batch_no: 'RQ3549', user: 'Asha', note: 'Opening stock' }]),
        ),
    });

    it('hides cost and stock actions from a cashier', async () => {
        const cashierView = detail({ batches: [batch({ cost_paise: undefined, value_paise: undefined })] });
        mockServer(routes(['sales.create'], cashierView));
        renderApp('/products/1');

        expect(await screen.findByText('RQ3549', { selector: 'td.font-medium' })).toBeInTheDocument();
        expect(screen.queryByRole('columnheader', { name: 'Cost' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add stock' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Adjust' })).not.toBeInTheDocument();
        expect(screen.getByText('Opening stock', { selector: 'td' })).toBeInTheDocument();
    });

    it('adds stock for a medicine only with batch and expiry, in strips', async () => {
        const calls = mockServer({ ...routes(['stock.adjust', 'stock.view_cost']), 'POST /products/1/opening-stock': () => ({ status: 201, body: { adjustment_id: 1 } }) });
        const user = userEvent.setup();
        renderApp('/products/1');

        await user.click(await screen.findByRole('button', { name: 'Add stock' }));
        const dialog = screen.getByRole('dialog');
        await user.type(within(dialog).getByLabelText('Quantity'), '12');
        await user.type(within(dialog).getByLabelText(/Purchase cost per strip/), '23.40');
        await user.click(within(dialog).getByRole('button', { name: 'Add stock' }));

        expect(await within(dialog).findAllByText('Please fill this in.')).toHaveLength(2); // batch and expiry
        expect(calls.some((c) => c.method === 'POST')).toBe(false);

        await user.type(within(dialog).getByLabelText('Batch no.'), 'kc8046');
        await user.type(within(dialog).getByLabelText('Expiry'), '2028-05');
        await user.click(within(dialog).getByRole('button', { name: 'Add stock' }));

        await waitFor(() => expect(screen.getByText('Stock added')).toBeInTheDocument());
        const post = calls.find((c) => c.method === 'POST')!;
        expect(post.headers['Idempotency-Key']).toBeTruthy();
        expect(post.body.items[0]).toEqual({ qty: 12, unit: 'pack', cost_paise: 2340, mrp_paise: 3000, batch_no: 'KC8046', expiry_month: '2028-05' });
    });

    it('needs a reason for an adjustment and shows a business refusal in plain words', async () => {
        const calls = mockServer({
            ...routes(['stock.adjust']),
            'POST /stock/batches/7/adjust': () => ({ status: 409, body: { code: 'insufficient_stock', message: 'Not enough stock in this batch.' } }),
        });
        const user = userEvent.setup();
        renderApp('/products/1');

        await user.click(await screen.findByRole('button', { name: 'Adjust' }));
        const dialog = screen.getByRole('dialog');
        await user.type(within(dialog).getByLabelText(/Quantity \(tablets\)/), '5');
        await user.click(within(dialog).getByRole('button', { name: /Damaged/ }));
        expect(await within(dialog).findByText(/Write the reason/)).toBeInTheDocument();

        await user.clear(within(dialog).getByLabelText(/Quantity \(tablets\)/));
        await user.type(within(dialog).getByLabelText(/Quantity \(tablets\)/), '500');
        await user.type(within(dialog).getByLabelText('Reason'), 'Bottle broke');
        await user.click(within(dialog).getByRole('button', { name: /Damaged/ }));
        expect(await within(dialog).findByText('Only 12 strips + 5 tablets available.')).toBeInTheDocument();
        expect(calls.some((c) => c.method === 'POST')).toBe(false);

        await user.clear(within(dialog).getByLabelText(/Quantity \(tablets\)/));
        await user.type(within(dialog).getByLabelText(/Quantity \(tablets\)/), '5');
        await user.click(within(dialog).getByRole('button', { name: /Damaged/ }));
        expect(await within(dialog).findByText('There is not enough stock in this batch.')).toBeInTheDocument();
        expect(calls.find((c) => c.method === 'POST')!.body).toEqual({ action: 'write_off_damage', qty: 5, bucket: 'sellable', note: 'Bottle broke' });
    });
});

describe('expiry alerts', () => {
    it('lists expired batches with their value and offers to set them aside', async () => {
        const expired = { ...batch({ id: 8, batch_no: 'OLD1', expiry_date: '2026-10-31', days_to_expiry: -11, expiry_status: 'expired', qty_sellable: 30, value_paise: 7020 }), product_id: 1, product_name: 'Cetzine 10 Tablet', product_type: 'medical', base_unit: 'TAB', pricing_unit: 'STRIP', pack_size: 10 };
        const calls = mockServer({
            ...base(['stock.adjust', 'stock.view_cost']),
            'GET /stock/summary': ok({ products: 80, medical_products: 40, general_products: 40, low_stock: 5, out_of_stock: 4, expired_batches: 1, expiring_30: 6, expiring_60: 6, expiring_90: 13, schedule_not_set: 2 }),
            'GET /stock/batches': (call) => ok({ data: call.path.includes('status=expired') ? [expired] : [] })(call),
        });
        const user = userEvent.setup();
        renderApp('/expiry');

        const line = (await screen.findByRole('link', { name: 'Cetzine 10 Tablet' })).closest('tr')!;
        expect(within(line).getByText('3 strips')).toBeInTheDocument();
        expect(within(line).getByText('₹70.20')).toBeInTheDocument();
        expect(within(line).getByText('Oct 2026')).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /30 days/ })).toHaveTextContent('6');

        await user.click(within(line).getByRole('button', { name: 'Set aside' }));
        expect(within(screen.getByRole('dialog')).getByLabelText('What happened?')).toHaveValue('mark_unsellable');

        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }));
        await user.click(screen.getByRole('tab', { name: /60 days/ }));
        expect(await screen.findByText('Nothing is expiring in this period.')).toBeInTheDocument();
        expect(calls.some((c) => c.path.includes('status=expiring') && c.path.includes('days=60'))).toBe(true);
    });
});
