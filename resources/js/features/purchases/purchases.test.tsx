import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import i18n from '@/lib/i18n';
import type { Purchase, PurchasePreview, Supplier, SupplierDetail } from './api';

type Call = { method: string; path: string; body: any; headers: Record<string, string> };
type Handler = (call: Call) => { status: number; body: unknown };

function mockServer(routes: Record<string, Handler>) {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit = {}) => {
            const path = url.replace('/api/v1', '');
            const call: Call = { method: init.method ?? 'GET', path, body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> };
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

const manager = {
    id: 7, name: 'Sunita', username: 'manager', locale: 'en', is_pharmacist: false, roles: ['store_manager'],
    permissions: ['purchases.manage', 'suppliers.manage', 'products.manage'], store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
};
const cashier = { ...manager, id: 4, name: 'Ravi', roles: ['cashier'], permissions: ['sales.create'] };

const supplier: Supplier = {
    id: 3, name: 'Mahalaxmi Pharma', kind: 'distributor', contact_person: 'Rahul', mobile: '9822000001', address: null, gstin: '27ABCDE1234F1Z5',
    drug_licence_no: null, payment_terms_days: 15, notes: null, balance_paise: 125000, last_payment_at: null,
};

const supplierDetail: SupplierDetail = {
    ...supplier,
    contacts: [{ id: 1, name: 'Amol (MR)', role: 'mr', company: 'Cipla', mobile: '9822000009', visit_day: 2, schemes: '10+1 on Crocin', notes: null }],
    entries: [
        { id: 2, date: '2026-11-26', type: 'payment', credit_paise: 0, debit_paise: 50000, balance_after_paise: 125000, note: 'P26-27/000001', purchase_id: null, return_id: null },
        { id: 1, date: '2026-11-26', type: 'purchase', credit_paise: 175000, debit_paise: 0, balance_after_paise: 175000, note: 'P26-27/000001 / INV-778', purchase_id: 9, return_id: null },
    ],
    purchases: [{ id: 9, purchase_no: 'P26-27/000001', supplier_invoice_no: 'INV-778', received_date: '2026-11-26', status: 'confirmed', total_paise: 175000, paid_paise: 50000, due_date: '2026-12-11' }],
};

const crocinRow = {
    id: 1, type: 'medical', name: 'Crocin 500 Tablet', name_local: null, sku: 'CRO500', brand: null, category: null, generic_name: 'Paracetamol', strength: '500 mg',
    schedule: 'NONE', schedule_set: true, mrp_paise: 3000, selling_price_paise: 3000, base_unit: 'TAB', pricing_unit: 'STRIP', pack_size: 10, rack_location: null,
    is_active: true, min_stock_qty: 0, stock_qty: 0, expired_qty: 0, unsellable_qty: 0, nearest_expiry: null, stock_status: 'out',
};

/** Prices like the server: rate × qty less discount, GST 5 %, cost shared with free packs. */
function previewFor(call: Call): PurchasePreview {
    const lines = (call.body.lines as any[]).map((line, index) => {
        const gross = line.rate_paise * line.qty;
        const taxable = gross - Math.round((gross * (line.discount_percent ?? 0)) / 100);
        const tax = Math.round(taxable * 0.05);
        const qtyBase = (line.qty + (line.free_qty ?? 0)) * 10;
        return {
            index, product_name: 'Crocin 500 Tablet', unit_code: 'STRIP', qty: line.qty, free_qty: line.free_qty ?? 0, qty_base: qtyBase, gross_paise: gross,
            discount_paise: gross - taxable, tax_rate_percent: '5.00', taxable_paise: taxable, cgst_paise: Math.floor(tax / 2), sgst_paise: tax - Math.floor(tax / 2),
            igst_paise: 0, cess_paise: 0, line_total_paise: taxable + tax, cost_per_pack_paise: Math.round((taxable / qtyBase) * 10), pack_size: 10, pricing_unit: 'STRIP', mrp_paise: line.mrp_paise ?? 3000,
        };
    });
    const sum = (k: keyof (typeof lines)[number]) => lines.reduce((s, l) => s + (l[k] as number), 0);
    const before = sum('taxable_paise') + sum('cgst_paise') + sum('sgst_paise') + (call.body.other_charges_paise ?? 0);
    const total = Math.round(before / 100) * 100;
    return {
        lines,
        errors: lines.filter((l) => l.qty_base === 0).map((l) => ({ index: l.index, code: 'qty_required', product: l.product_name })),
        totals: {
            gross_paise: sum('gross_paise'), discount_paise: sum('discount_paise'), taxable_paise: sum('taxable_paise'), cgst_paise: sum('cgst_paise'), sgst_paise: sum('sgst_paise'),
            igst_paise: 0, cess_paise: 0, other_charges_paise: call.body.other_charges_paise ?? 0, round_off_paise: total - before, total_paise: total,
        },
        inter_state: false,
    };
}

const purchase = (overrides: Partial<Purchase> = {}): Purchase => ({
    id: 9, purchase_no: 'P26-27/000001', status: 'confirmed', supplier: { id: 3, name: 'Mahalaxmi Pharma', gstin: '27ABCDE1234F1Z5', balance_paise: 125000, payment_terms_days: 15 },
    supplier_invoice_no: 'INV-778', invoice_date: '2026-11-26', received_date: '2026-11-26', total_paise: 18900, paid_paise: 0, due_date: '2026-12-11', lines: 1,
    inter_state: false, other_charges_paise: 0, invoice_total_paise: null, notes: null, by: 'Sunita', confirmed_at: '2026-11-26T05:00:00+00:00', cancelled: null,
    totals: { gross_paise: 20000, discount_paise: 2000, taxable_paise: 18000, cgst_paise: 450, sgst_paise: 450, igst_paise: 0, cess_paise: 0, other_charges_paise: 0, round_off_paise: 0, total_paise: 18900 },
    items: [{
        line_no: 1, product_id: 1, product_name: 'Crocin 500 Tablet', hsn_code: '3004', unit_code: 'STRIP', unit_factor: 10, qty: 10, free_qty: 1, qty_base: 110, rate_paise: 2000,
        discount_percent: '10.00', discount_paise: 2000, tax_rate_percent: '5.00', taxable_paise: 18000, tax_paise: 900, line_total_paise: 18900, batch_no: 'B501', expiry_date: '2028-05-31',
        mfg_date: null, mrp_paise: 3200, selling_price_paise: null, cost_rate: '163.636364', batch_id: 44,
    }],
    payments: [],
    ...overrides,
});

function base(profile: object = manager): Record<string, Handler> {
    return {
        'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr'] }),
        'GET /auth/me': ok(profile),
        'GET /suppliers': ok({ data: [supplier], meta: { total: 1, page: 1, last_page: 1 }, total_owed_paise: 125000, suppliers_owed: 1 }),
        'GET /suppliers/visits': ok({ data: [] }),
        'GET /suppliers/3': ok(supplierDetail),
        'GET /products': ok({ data: [crocinRow], meta: { total: 1, page: 1, last_page: 1 } }),
        'POST /purchases/preview': (call) => ok(previewFor(call))(),
        'GET /dashboard': ok({ today: '2026-11-26', mine: { bills: 0, sales_paise: 0, udhar_paise: 0 }, udhar: { total_paise: 0, customers: 0, top: [] }, low_stock: [], expiring: [] }),
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
    localStorage.clear();
});

describe('suppliers', () => {
    it('lists who is owed money and adds a supplier with an opening balance', async () => {
        const calls = mockServer({
            ...base(),
            'POST /suppliers': (call) => ({ status: 201, body: { ...supplier, id: 5, name: call.body.name, balance_paise: call.body.opening_balance_paise } }),
            'GET /suppliers/5': ok({ ...supplierDetail, id: 5, name: 'Jay Agencies', balance_paise: 250000, contacts: [], entries: [], purchases: [] }),
        });
        const user = userEvent.setup();
        renderApp('/suppliers');

        expect(await screen.findByText('Mahalaxmi Pharma')).toBeInTheDocument();
        expect(screen.getAllByText('₹1,250.00').length).toBeGreaterThan(0); // banner and row

        await user.click(screen.getByRole('button', { name: 'Add supplier' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Supplier name'), 'Jay Agencies');
        await user.type(within(dialog).getByLabelText(/Already owed/), '2500');
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));

        const post = calls.find((c) => c.method === 'POST' && c.path === '/suppliers');
        expect(post?.body).toMatchObject({ name: 'Jay Agencies', opening_balance_paise: 250000, kind: 'distributor' });
        expect(post?.headers['Idempotency-Key']).toBeTruthy();
        expect(await screen.findByRole('heading', { name: 'Jay Agencies' })).toBeInTheDocument();
    });

    it('shows the statement, the MR, and pays the supplier', async () => {
        const calls = mockServer({
            ...base(),
            'POST /suppliers/3/payments': () => ({ status: 201, body: { ...supplier, balance_paise: 25000 } }),
        });
        const user = userEvent.setup();
        renderApp('/suppliers/3');

        expect(await screen.findByRole('heading', { name: 'Mahalaxmi Pharma' })).toBeInTheDocument();
        expect(screen.getByText(/Amol \(MR\)/)).toBeInTheDocument();
        expect(screen.getByText('Visits on Tuesday')).toBeInTheDocument();
        expect(screen.getByText('P26-27/000001 / INV-778')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Pay supplier' }));
        const dialog = await screen.findByRole('dialog');
        const amount = within(dialog).getByLabelText(/Amount received/);
        expect(amount).toHaveValue('1250'); // the whole balance is offered
        await user.clear(amount);
        await user.type(amount, '1000');
        await user.click(within(dialog).getByRole('button', { name: 'Pay supplier' }));

        const pay = calls.find((c) => c.method === 'POST' && c.path === '/suppliers/3/payments');
        expect(pay?.body).toMatchObject({ amount_paise: 100000, method: 'cash', purchase_id: null });
        expect(pay?.headers['Idempotency-Key']).toBeTruthy();
        await waitFor(() => expect(screen.getByText('₹1,000.00 paid.')).toBeInTheDocument());
    });

    it('keeps a cashier out', async () => {
        mockServer(base(cashier));
        renderApp('/suppliers');
        expect((await screen.findAllByText('Dashboard')).length).toBeGreaterThan(0); // sent home
        await waitFor(() => expect(screen.queryByRole('heading', { name: 'Suppliers' })).not.toBeInTheDocument());
        expect(screen.queryByText('Mahalaxmi Pharma')).not.toBeInTheDocument();
    });
});

describe('new purchase', () => {
    it('adds a line, shows the server totals, saves the draft and confirms with a part payment', async () => {
        const calls = mockServer({
            ...base(),
            'POST /purchases': (call) => ({ status: 201, body: purchase({ id: 12, status: 'draft', purchase_no: null, total_paise: previewFor(call).totals.total_paise }) }),
            'POST /purchases/12/confirm': () => ({ status: 200, body: purchase({ id: 12, paid_paise: 10000, payments: [{ direction: 'out', method: 'upi', amount_paise: 10000, reference_no: null, at: '2026-11-26T05:00:00+00:00' }] }) }),
            'GET /purchases/12': ok(purchase({ id: 12, paid_paise: 10000, payments: [{ direction: 'out', method: 'upi', amount_paise: 10000, reference_no: null, at: '2026-11-26T05:00:00+00:00' }] })),
        });
        const user = userEvent.setup();
        renderApp('/purchases/new');

        await screen.findByRole('option', { name: 'Mahalaxmi Pharma' });
        await user.selectOptions(screen.getByLabelText('Supplier'), '3');
        await user.type(screen.getByLabelText('Supplier invoice no.'), 'INV-778');

        const search = screen.getByRole('searchbox', { name: /Type a product name/ });
        await user.type(search, 'cro');
        await user.click(await screen.findByRole('option', { name: /Crocin 500 Tablet/ }));

        await user.type(screen.getByLabelText('Batch no.'), 'b501');
        expect(screen.getByLabelText('Batch no.')).toHaveValue('B501');
        await user.type(screen.getByLabelText('Qty'), '10');
        await user.type(screen.getByLabelText('Free'), '1');
        await user.type(screen.getByLabelText('Rate ₹'), '20');
        await user.type(screen.getByLabelText('Disc %'), '10');

        // Server figures: 10 × 20 = 200, −10 % = 180, +5 % = 189.
        await waitFor(() => expect(screen.getByText('₹189.00')).toBeInTheDocument());
        expect(screen.getByText('Cost ₹16.36 per strip')).toBeInTheDocument();
        const lastPreview = [...calls].reverse().find((c) => c.path === '/purchases/preview');
        expect(lastPreview?.body.lines[0]).toMatchObject({ product_id: 1, qty: 10, free_qty: 1, rate_paise: 2000, discount_percent: 10, batch_no: 'B501', unit: 'pack' });

        await user.click(screen.getByRole('button', { name: 'Confirm & add to stock' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText(/Paid now/), '100');
        await user.selectOptions(within(dialog).getByLabelText('Paid by'), 'upi');
        expect(within(dialog).getByText('You will owe ₹89.00 to the supplier.')).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));

        const save = calls.find((c) => c.method === 'POST' && c.path === '/purchases');
        expect(save?.body).toMatchObject({ supplier_id: 3, supplier_invoice_no: 'INV-778', lines: [{ product_id: 1, qty: 10, free_qty: 1, rate_paise: 2000 }] });
        const confirm = calls.find((c) => c.method === 'POST' && c.path === '/purchases/12/confirm');
        expect(confirm?.body).toEqual({ payment: { amount_paise: 10000, method: 'upi' } });
        expect(confirm?.headers['Idempotency-Key']).toBeTruthy();

        expect(await screen.findByRole('heading', { name: /P26-27\/000001/ })).toBeInTheDocument();
        expect(screen.getByText('Part paid')).toBeInTheDocument();
        expect(localStorage.getItem('rmgsms.purchase_draft')).toBeNull();
    });

    it('warns when the supplier invoice number was already entered and lets the user save anyway', async () => {
        let attempts = 0;
        const calls = mockServer({
            ...base(),
            'POST /purchases': (call) => {
                attempts++;
                if (!call.body.confirm_duplicate) {
                    return { status: 409, body: { code: 'duplicate_supplier_invoice', message: 'dup', purchase: { id: 9, purchase_no: 'P26-27/000001', status: 'confirmed', total_paise: 18900, received_date: '2026-11-26' } } };
                }
                return { status: 201, body: purchase({ id: 13, status: 'draft', purchase_no: null }) };
            },
            'GET /purchases/13': ok(purchase({ id: 13, status: 'draft', purchase_no: null })),
        });
        const user = userEvent.setup();
        renderApp('/purchases/new');

        await screen.findByRole('option', { name: 'Mahalaxmi Pharma' });
        await user.selectOptions(screen.getByLabelText('Supplier'), '3');
        await user.type(screen.getByLabelText('Supplier invoice no.'), 'INV-778');
        await user.type(screen.getByRole('searchbox', { name: /Type a product name/ }), 'cro');
        await user.click(await screen.findByRole('option', { name: /Crocin 500 Tablet/ }));
        await user.type(await screen.findByLabelText('Qty'), '2');
        await user.type(screen.getByLabelText('Rate ₹'), '20');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(await screen.findByText(/Invoice INV-778 from this supplier was already entered/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save anyway' }));
        await waitFor(() => expect(attempts).toBe(2));
        expect(calls.filter((c) => c.method === 'POST' && c.path === '/purchases').at(-1)?.body.confirm_duplicate).toBe(true);
        expect(await screen.findByRole('heading', { name: /Purchase draft/ })).toBeInTheDocument();
    });

    it('shows a confirmed purchase and cancels it with a reason', async () => {
        const calls = mockServer({
            ...base(),
            'GET /purchases/9': ok(purchase()),
            'POST /purchases/9/cancel': () => ({ status: 200, body: purchase({ status: 'cancelled', cancelled: { at: '2026-11-26T06:00:00+00:00', reason: 'Wrong supplier' } }) }),
        });
        const user = userEvent.setup();
        renderApp('/purchases/9');

        expect(await screen.findByRole('heading', { name: /P26-27\/000001/ })).toBeInTheDocument();
        expect(screen.getByText('Crocin 500 Tablet')).toBeInTheDocument();
        expect(screen.getAllByText('₹189.00').length).toBeGreaterThan(0);

        await user.click(screen.getByRole('button', { name: 'Cancel purchase' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Reason'), 'Wrong supplier');
        await user.click(within(dialog).getByRole('button', { name: 'Cancel purchase' }));

        expect(calls.find((c) => c.path === '/purchases/9/cancel')?.body).toEqual({ reason: 'Wrong supplier' });
        await waitFor(() => expect(screen.getByText('Purchase cancelled.')).toBeInTheDocument());
    });
});

describe('return to supplier', () => {
    it('picks a batch, sets the quantity and makes a debit note', async () => {
        const lot = { id: 44, product_id: 1, product_name: 'Crocin 500 Tablet', batch_no: 'B501', expiry_date: '2028-05-31', expired: false, qty_sellable: 110, qty_unsellable: 0, cost_rate: '163.636364', supplier_id: 3, base_unit: 'TAB', pack_size: 10, pricing_unit: 'STRIP' };
        const calls = mockServer({
            ...base(),
            'GET /purchase-returns/lots': ok({ data: [lot] }),
            'POST /purchase-returns': () => ({ status: 201, body: { id: 2 } }),
            'GET /purchase-returns/2': ok({
                id: 2, return_no: 'D26-27/000001', return_date: '2026-11-26', created_at: '2026-11-26T06:00:00+00:00', supplier: { id: 3, name: 'Mahalaxmi Pharma', balance_paise: 108200 },
                reason: 'Damaged', settlement: 'reduce_balance', refund_method: null, by: 'Sunita',
                items: [{ line_no: 1, product_name: 'Crocin 500 Tablet', batch_no: 'B501', bucket: 'sellable', qty_base: 100, cost_rate: '163.636364', tax_rate_percent: '5.00', taxable_paise: 16364, line_total_paise: 17182 }],
                totals: { taxable_paise: 16364, cgst_paise: 409, sgst_paise: 409, igst_paise: 0, cess_paise: 0, round_off_paise: 18, total_paise: 17200 },
            }),
        });
        const user = userEvent.setup();
        renderApp('/purchase-returns/new?supplier=3');

        await user.click(await screen.findByRole('button', { name: /Crocin 500 Tablet/ }));
        const qty = screen.getByLabelText(/Quantity/);
        expect(qty).toHaveValue('110');
        await user.clear(qty);
        await user.type(qty, '100');
        await user.type(screen.getByLabelText('Reason'), 'Damaged');
        await user.click(screen.getByRole('button', { name: 'Make debit note' }));

        const post = calls.find((c) => c.method === 'POST' && c.path === '/purchase-returns');
        expect(post?.body).toEqual({ supplier_id: 3, reason: 'Damaged', settlement: 'reduce_balance', refund_method: null, lines: [{ batch_id: 44, bucket: 'sellable', qty_base: 100 }] });
        expect(await screen.findByRole('heading', { name: /D26-27\/000001/ })).toBeInTheDocument();
        expect(screen.getByText('₹172.00')).toBeInTheDocument();
    });
});
