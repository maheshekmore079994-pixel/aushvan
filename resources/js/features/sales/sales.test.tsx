import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import { scanMemory } from '@/components/CameraScanner';
import { cleanCode } from '@/lib/barcodeReader';
import i18n from '@/lib/i18n';
import { ulid } from '@/lib/ulid';
import type { Bill, PosProduct, Preview } from './api';

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
            return new Response(JSON.stringify(result.body), { status: result.status });
        }),
    );
    return calls;
}

const ok = (body: unknown) => (_call?: Call) => ({ status: 200, body });

const me = (extra: object = {}) => ({
    id: 4, name: 'Ravi', username: 'cashier', locale: 'en', is_pharmacist: false, roles: ['cashier'],
    permissions: ['sales.create', 'customers.manage', 'credit.receive'], store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' }, ...extra,
});

const crocin: PosProduct = {
    id: 1, type: 'medical', name: 'Crocin 500 Tablet', name_local: null, generic_name: 'Paracetamol', strength: '500 mg', schedule: 'NONE',
    needs_prescription: false, base_unit: 'TAB', pricing_unit: 'STRIP', pack_size: 10, allow_loose_sale: true, tracks_batch: true,
    rack_location: 'A1', price_paise: 3000, stock_qty: 130, expired_qty: 0,
    batches: [
        { id: 11, batch_no: 'NEAR', expiry_date: '2027-01-31', days_to_expiry: 81, qty: 30, price_paise: 3000, mrp_paise: 3000 },
        { id: 12, batch_no: 'FAR', expiry_date: '2028-05-31', days_to_expiry: 567, qty: 100, price_paise: 3000, mrp_paise: 3000 },
    ],
};

const totals = (total: number) => ({
    gross_paise: total, discount_paise: 0, taxable_paise: Math.round(total / 1.05), cgst_paise: 0, sgst_paise: 0, igst_paise: 0, cess_paise: 0,
    round_off_paise: 0, total_paise: total,
});

/** Prices every cart line at Rs 30 a strip / Rs 3 a tablet, like the server would. */
function previewFor(call: Call, requires = { prescription: false, pharmacist: false }): Preview {
    const lines = call.body.lines.map((line: any, index: number) => ({
        index, product_name: 'Crocin 500 Tablet', batch_no: 'NEAR', expiry_date: '2027-01-31', unit_code: line.unit === 'base' ? 'TAB' : 'STRIP',
        qty: line.qty, line_total_paise: line.qty * (line.unit === 'base' ? 300 : 3000), discount_paise: 0,
    }));
    return { lines, errors: [], totals: totals(lines.reduce((s: number, l: any) => s + l.line_total_paise, 0)), doc_kind: 'tax_invoice', requires };
}

const bill = (overrides: Partial<Bill> = {}): Bill => ({
    id: 55, invoice_no: '26-27/000123', doc_kind: 'tax_invoice', status: 'completed', cancelled: null, can_cancel: false, can_return: false, returns: [],
    sale_date: '2026-11-11', completed_at: '2026-11-11T05:00:00+00:00',
    cashier: 'Ravi', pharmacist: null, customer: null, prescription: null,
    shop: {
        name: 'Shree Medical & General Stores', address: 'Main Road', village: 'Wadgaon', taluka: null, district: 'Pune', pincode: '412106',
        phone: '9800000000', gst_mode: 'regular', gstin: '27AAAAA0000A1Z5', drug_licences: ['MH-PZ4-000001'], footer: 'Thank you. Visit again.',
    },
    has_medicines: true,
    items: [{
        line_no: 1, product_name: 'Crocin 500 Tablet', hsn_code: '3004', schedule_code: 'NONE', batch_no: 'NEAR', expiry_date: '2027-01-31',
        unit_code: 'STRIP', qty: 2, mrp_paise: 3000, pack_price_paise: 3000, pack_factor: 10, unit_factor: 10, gross_paise: 6000,
        discount_paise: 0, tax_rate_percent: '5.00', line_total_paise: 6000, returned_qty: 0,
    }],
    totals: { ...totals(6000), cgst_paise: 143, sgst_paise: 143, paid_paise: 6000, credit_paise: 0 },
    tax_summary: [{ rate_percent: '5.00', taxable_paise: 5714, cgst_paise: 143, sgst_paise: 143, igst_paise: 0, cess_paise: 0 }],
    payments: [{ method: 'cash', amount_paise: 6000, reference_no: null }],
    refunds: [],
    ...overrides,
});

function base(profile = me()): Record<string, Handler> {
    return {
        'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr'] }),
        'GET /auth/me': ok(profile),
        'GET /pos/search': (call) => ok({ data: call.path.includes('quick=1') ? [] : [crocin], matched_barcode: call.path.includes('q=890'), barcode_unit: 'pack' })(call),
        'POST /sales/preview': (call) => ok(previewFor(call))(call),
        'GET /doctors': ok({ data: [] }),
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
});

describe('ulid', () => {
    it('makes 26-character ids that sort by time and do not repeat', () => {
        const early = ulid(1_700_000_000_000);
        const late = ulid(1_800_000_000_000);
        expect(early).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(early < late).toBe(true);
        expect(new Set(Array.from({ length: 200 }, () => ulid())).size).toBe(200);
    });
});

describe('new sale', () => {
    it('scans a barcode twice, takes cash and opens the printed bill', async () => {
        const calls = mockServer({ ...base(), 'POST /sales': () => ({ status: 201, body: bill() }), 'GET /sales/55': ok(bill()) });
        const user = userEvent.setup();
        renderApp('/sale');

        const search = await screen.findByRole('searchbox');
        await user.type(search, '8900000000011{Enter}');
        expect(await screen.findByDisplayValue('1')).toBeInTheDocument(); // one strip in the cart
        await user.type(search, '8900000000011{Enter}');
        expect(await screen.findByDisplayValue('2')).toBeInTheDocument(); // same item again adds to it
        await waitFor(() => expect(screen.getAllByText('₹60.00').length).toBeGreaterThan(0));

        await user.click(screen.getByRole('button', { name: /^Pay/ }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText('Cash')).toHaveValue('60'); // all cash by default
        await user.type(within(dialog).getByLabelText(/Cash given/), '100');
        expect(within(dialog).getByText('Give back ₹40.00')).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));

        expect(await screen.findByText('Tax Invoice')).toBeInTheDocument();
        expect(screen.getByText('26-27/000123')).toBeInTheDocument();
        expect(screen.getByText('GSTIN: 27AAAAA0000A1Z5')).toBeInTheDocument();
        expect(screen.getByText('Drug Lic. No.: MH-PZ4-000001')).toBeInTheDocument();
        expect(screen.getByText(/Batch no. NEAR · Expiry Jan 2027 · HSN 3004 · GST 5%/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'New Sale' })).toHaveFocus(); // Enter starts the next bill

        const sale = calls.find((c) => c.method === 'POST' && c.path === '/sales')!;
        expect(sale.body.ulid).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(sale.body.lines).toEqual([{ product_id: 1, unit: 'pack', qty: 2, batch_id: null, discount_percent: 0 }]);
        expect(sale.body.payments).toEqual([{ method: 'cash', amount_paise: 6000 }]);
        expect(JSON.parse(localStorage.getItem('rmgsms.cart.4')!).lines).toEqual([]); // the cart is emptied for the next customer
    });

    it('brings the bill back after the browser was closed, with the same id', async () => {
        localStorage.setItem('rmgsms.cart.4', JSON.stringify({ id: '01JD000000000000000000CART', lines: [{ key: 'a', product: crocin, unit: 'base', qty: 3, batch_id: 12, discount: '' }], customer: null }));
        const calls = mockServer({ ...base(), 'POST /sales': () => ({ status: 200, body: bill() }), 'GET /sales/55': ok(bill()) });
        const user = userEvent.setup();
        renderApp('/sale');

        expect(await screen.findByDisplayValue('3')).toBeInTheDocument();
        await waitFor(() => expect(screen.getAllByText('₹9.00').length).toBeGreaterThan(0)); // 3 loose tablets
        expect(screen.getByLabelText('Unit')).toHaveValue('base');

        await user.click(screen.getByRole('button', { name: /^Pay/ }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Complete sale' }));
        await screen.findByText('Tax Invoice');

        const sale = calls.find((c) => c.method === 'POST' && c.path === '/sales')!;
        expect(sale.body.ulid).toBe('01JD000000000000000000CART'); // so a bill saved before the power cut is not made twice
        expect(sale.body.lines[0]).toMatchObject({ unit: 'base', qty: 3, batch_id: 12 });
    });

    it('will not give udhar to a walk-in customer, then allows it once a customer is chosen', async () => {
        const ramesh = { id: 7, name: 'Ramesh Patil', mobile: '9800000010', village: 'Wadgaon', address: null, notes: null, credit_limit_paise: 0, balance_paise: 30000, last_payment_at: null };
        const calls = mockServer({
            ...base(),
            'GET /customers': ok({ data: [ramesh], meta: { total: 1, page: 1, last_page: 1 }, total_owed_paise: 30000, customers_owing: 1 }),
            'POST /sales': () => ({ status: 201, body: bill() }),
            'GET /sales/55': ok(bill()),
        });
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), 'croc');
        await user.click(within(await screen.findByRole('option', { name: /Crocin 500 Tablet/ })).getByRole('button'));
        await waitFor(() => expect(screen.getAllByText('₹30.00').length).toBeGreaterThan(0));

        await user.click(screen.getByRole('button', { name: /^Pay/ }));
        let dialog = screen.getByRole('dialog');
        await user.clear(within(dialog).getByLabelText('Cash'));
        await user.type(within(dialog).getByLabelText('Cash'), '10');
        expect(within(dialog).getByText(/Udhar ₹20.00/)).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));
        expect(await within(dialog).findByText('Choose a customer to give udhar.')).toBeInTheDocument();
        expect(calls.some((c) => c.path === '/sales')).toBe(false);

        await user.click(within(dialog).getByRole('button', { name: 'Choose customer' }));
        await user.click(await screen.findByRole('button', { name: /Ramesh Patil/ }));
        expect(await screen.findByText('Already owes ₹300.00')).toBeInTheDocument();

        dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText('Cash')).toHaveValue('10'); // not reset to "all cash" by the detour
        expect(within(dialog).getByText(/Udhar ₹20.00 — Ramesh Patil/)).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));

        await screen.findByText('Tax Invoice');
        const sale = calls.find((c) => c.path === '/sales')!;
        expect(sale.body).toMatchObject({ customer_id: 7, payments: [{ method: 'cash', amount_paise: 1000 }] });
    });

    it('asks for doctor, patient and the pharmacist’s PIN for prescription medicine', async () => {
        const calls = mockServer({
            ...base(),
            'POST /sales/preview': (call) => ok(previewFor(call, { prescription: true, pharmacist: true }))(call),
            'GET /pos/pharmacists': ok({ data: [{ id: 2, name: 'Dr. Suresh Kulkarni' }] }),
            'POST /sales': () => ({ status: 409, body: { code: 'pharmacist_required', message: 'x' } }),
        });
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), 'croc{Enter}');
        await waitFor(() => expect(screen.getAllByText('₹30.00').length).toBeGreaterThan(0));
        await user.click(screen.getByRole('button', { name: /^Pay/ }));
        const dialog = screen.getByRole('dialog');

        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));
        expect(await within(dialog).findByText(/Enter the doctor’s name and the patient’s name/)).toBeInTheDocument();

        await user.type(within(dialog).getByLabelText(/Doctor’s name/), 'Dr. Deshmukh');
        await user.type(within(dialog).getByLabelText(/Patient’s name/), 'Sunita Jadhav');
        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));
        expect(await within(dialog).findByText(/pharmacist must approve/)).toBeInTheDocument();
        expect(calls.some((c) => c.path === '/sales')).toBe(false);

        await user.selectOptions(await within(dialog).findByLabelText('Pharmacist'), '2');
        await user.type(within(dialog).getByLabelText('Counter PIN'), '0000');
        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));

        await waitFor(() => expect(calls.some((c) => c.path === '/sales')).toBe(true));
        const sale = calls.find((c) => c.path === '/sales')!;
        expect(sale.body.prescription).toMatchObject({ prescriber_name: 'Dr. Deshmukh', patient_name: 'Sunita Jadhav' });
        expect(sale.body.pharmacist).toEqual({ user_id: 2, pin: '0000' });
        expect(await within(dialog).findByText(/pharmacist must approve/)).toBeInTheDocument(); // wrong PIN refused by the server
        expect(screen.getByDisplayValue('1')).toBeInTheDocument(); // the cart is untouched
    });

    it('offers the shop’s doctors on the prescription step and sends the picked one', async () => {
        const calls = mockServer({
            ...base(me({ is_pharmacist: true })),
            'POST /sales/preview': (call) => ok(previewFor(call, { prescription: true, pharmacist: true }))(call),
            'GET /doctors': ok({ data: [{ id: 3, name: 'Dr. Meena Joshi', reg_no: 'MMC-777', clinic: 'Joshi Nursing Home', address: 'Kalyan West', phone: null, is_active: true }] }),
            'POST /sales': () => ({ status: 201, body: bill({ prescription: { patient_name: 'Ramesh', prescriber_name: 'Dr. Meena Joshi', prescriber_address: 'Joshi Nursing Home, Kalyan West' } }) }),
            'GET /sales/55': ok(bill()),
        });
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), 'croc{Enter}');
        await waitFor(() => expect(screen.getAllByText('₹30.00').length).toBeGreaterThan(0));
        await user.click(screen.getByRole('button', { name: /^Pay/ }));
        const dialog = screen.getByRole('dialog');

        await user.type(within(dialog).getByLabelText(/Doctor’s name/), 'jo');
        await user.click(await within(dialog).findByRole('option', { name: /Dr. Meena Joshi/ }));
        expect(within(dialog).getByLabelText(/Doctor’s name/)).toHaveValue('Dr. Meena Joshi');
        expect(within(dialog).getByLabelText(/Doctor’s address/)).toHaveValue('Joshi Nursing Home, Kalyan West');
        expect(within(dialog).queryByText(/to the doctor list for next time/)).not.toBeInTheDocument(); // picked, not new
        await user.type(within(dialog).getByLabelText(/Patient’s name/), 'Ramesh');
        await user.click(within(dialog).getByRole('button', { name: 'Complete sale' }));

        await waitFor(() => expect(calls.some((c) => c.path === '/sales')).toBe(true));
        expect(calls.find((c) => c.path === '/sales')!.body.prescription).toMatchObject({ doctor_id: 3, prescriber_name: 'Dr. Meena Joshi', patient_name: 'Ramesh' });
    });

    it('shows when there is not enough stock and blocks payment', async () => {
        mockServer({
            ...base(),
            'POST /sales/preview': ok({ lines: [], errors: [{ index: 0, code: 'insufficient_stock', product: 'Crocin 500 Tablet', available: 13, unit: 'STRIP' }], totals: totals(0), doc_kind: 'tax_invoice', requires: { prescription: false, pharmacist: false } }),
        });
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), 'croc{Enter}');
        expect(await screen.findByText('Only 13 strips can be sold.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^Pay/ })).toBeDisabled();
    });

    it('lets the quantity box be emptied and retyped without sticking a 1 in front', async () => {
        const calls = mockServer(base());
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), '8900000000011{Enter}');
        const box = await screen.findByLabelText('Quantity');
        await user.clear(box);
        expect(box).toHaveValue(''); // empty while typing, not snapped back to 1
        await user.type(box, '20');
        expect(box).toHaveValue('20');
        await waitFor(() => expect(calls.filter((c) => c.path === '/sales/preview').at(-1)?.body.lines[0].qty).toBe(20));

        // Left empty, it goes back to the last real quantity.
        await user.clear(box);
        await user.tab();
        expect(box).toHaveValue('20');
    });

    it('says so when the server already holds an older bill for this cart, instead of calling it saved', async () => {
        // The first try saved 2 strips (Rs 60) but its answer was lost; the cart on screen is now 3 loose tablets (Rs 9).
        localStorage.setItem('rmgsms.cart.4', JSON.stringify({ id: '01JD000000000000000000CART', lines: [{ key: 'a', product: crocin, unit: 'base', qty: 3, batch_id: 12, discount: '' }], customer: null }));
        mockServer({ ...base(), 'POST /sales': () => ({ status: 200, body: bill() }), 'GET /sales/55': ok(bill()) });
        const user = userEvent.setup();
        renderApp('/sale');

        await waitFor(() => expect(screen.getAllByText('₹9.00').length).toBeGreaterThan(0));
        await user.click(screen.getByRole('button', { name: /^Pay/ }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Complete sale' }));

        expect(await screen.findByText(/already saved earlier as 26-27\/000123/)).toBeInTheDocument();
        expect(screen.queryByText('Saved — bill 26-27/000123')).not.toBeInTheDocument();
        expect(await screen.findByText('Tax Invoice')).toBeInTheDocument(); // the real bill is opened for checking
    });

    it('shows the sign-in screen when the session has ended on the server', async () => {
        mockServer({ ...base(), 'POST /sales/preview': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) });
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), '8900000000011{Enter}');
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('is not offered to someone who cannot bill', async () => {
        mockServer({ ...base(me({ permissions: [] })), 'GET /store': ok(null), 'GET /stock/summary': ok({ products: 0 }) });
        renderApp('/sale');

        await waitFor(() => expect(screen.queryByRole('searchbox')).not.toBeInTheDocument());
        expect(screen.queryByRole('link', { name: 'New Sale' })).not.toBeInTheDocument();
    });
});

describe('udhar', () => {
    const ramesh = { id: 7, name: 'Ramesh Patil', mobile: '9800000010', village: 'Wadgaon', address: null, notes: null, credit_limit_paise: 0, balance_paise: 30000, last_payment_at: null };
    const entries = [
        { id: 2, date: '2026-11-01', type: 'payment', debit_paise: 0, credit_paise: 20000, balance_after_paise: 30000, note: '26-27/000087', sale_id: 87 },
        { id: 1, date: '2026-11-01', type: 'sale', debit_paise: 50000, credit_paise: 0, balance_after_paise: 50000, note: '26-27/000087', sale_id: 87 },
    ];

    it('shows who owes what and the statement, and receives a part payment', async () => {
        const calls = mockServer({
            ...base(),
            'GET /customers': ok({ data: [ramesh], meta: { total: 1, page: 1, last_page: 1 }, total_owed_paise: 30000, customers_owing: 1 }),
            'GET /customers/7': ok({ ...ramesh, entries }),
            'POST /customers/7/payments': () => ({ status: 201, body: { ...ramesh, balance_paise: 20000 } }),
        });
        const user = userEvent.setup();
        renderApp('/udhar');

        expect(await screen.findByText('Udhar to collect')).toBeInTheDocument();
        expect(screen.getByText('1 customer')).toBeInTheDocument();
        await user.click(screen.getByRole('link', { name: /Ramesh Patil/ }));

        const billRow = (await screen.findByText('Bill')).closest('tr')!;
        expect(within(billRow).getAllByText('500.00')).toHaveLength(2); // amount bought and balance after it
        expect(within(billRow).getByRole('link', { name: '26-27/000087' })).toHaveAttribute('href', '/sales/87');

        await user.click(screen.getByRole('button', { name: 'Receive payment' }));
        const dialog = screen.getByRole('dialog');
        const amount = within(dialog).getByLabelText(/Amount received/);
        expect(amount).toHaveValue('300'); // the full balance is offered
        await user.clear(amount);
        await user.type(amount, '100');
        expect(within(dialog).getByText('Balance after this: ₹200.00')).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Receive payment' }));

        await waitFor(() => expect(screen.getByText('Received ₹100.00')).toBeInTheDocument());
        const payment = calls.find((c) => c.method === 'POST' && c.path === '/customers/7/payments')!;
        expect(payment.body).toEqual({ amount_paise: 10000, method: 'cash', reference_no: null });
    });
});

describe('camera scanner', () => {
    /** The barcode the camera (or the photo) is looking at just now. */
    let inView: string | string[] | null = null;
    const stopCamera = vi.fn();

    /** A phone or laptop with a working camera on a secure address. */
    function giveCamera(getUserMedia = vi.fn(async () => ({ getTracks: () => [{ stop: stopCamera }], getVideoTracks: () => [{ getCapabilities: () => ({}) }] }))) {
        Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } });
        Object.defineProperty(HTMLMediaElement.prototype, 'readyState', { configurable: true, get: () => 4 });
        HTMLMediaElement.prototype.play = vi.fn(async () => undefined);
        return getUserMedia;
    }

    beforeEach(() => {
        inView = null;
        stopCamera.mockClear();
        vi.stubGlobal('BarcodeDetector', class {
            static getSupportedFormats = async () => ['ean_13', 'code_128'];
            detect = async () => [inView ?? []].flat().map((rawValue) => ({ rawValue }));
        });
    });

    afterEach(() => {
        delete (navigator as unknown as Record<string, unknown>).mediaDevices;
        delete (HTMLMediaElement.prototype as unknown as Record<string, unknown>).readyState;
        vi.unstubAllGlobals();
    });

    const barcodeLookups = (calls: Call[]) => calls.filter((c) => c.path.startsWith('/pos/search?q=890')).length;

    it('accepts only what a scanner could have typed', () => {
        expect(cleanCode(' 8900000000011 ')).toBe('8900000000011');
        expect(cleanCode('')).toBeNull();
        expect(cleanCode(undefined)).toBeNull();
        expect(cleanCode('890\n1')).toBeNull();
        expect(cleanCode('9'.repeat(65))).toBeNull();
    });

    it('acts on a code only after two frames running, and never again while it stays in view', () => {
        const memory = scanMemory(3000);
        expect(memory.frame(['A'], 0)).toBeNull(); // one frame could be a misread
        expect(memory.frame(['A'], 120)).toBe('A');
        memory.done('A', 400);
        expect(memory.frame(['A'], 520)).toBeNull();
        expect(memory.frame([], 2000)).toBeNull(); // not read for a moment (poor light)…
        expect(memory.frame(['A'], 3300)).toBeNull(); // …still the same pack: under 3 s since it was last seen
        expect(memory.frame(['A'], 3420)).toBeNull();
        // Taken away for more than 3 s and shown again: counts as another pack.
        expect(memory.frame([], 3540)).toBeNull();
        expect(memory.frame(['A'], 6500)).toBeNull();
        expect(memory.frame(['A'], 6620)).toBe('A');
    });

    it('does not add over and over when two barcodes are in view and the reader flips between them', () => {
        const memory = scanMemory(3000);
        const acted: string[] = [];
        let now = 0;
        // A pack with a product barcode (A) and a batch barcode (B): the reader returns one, the other, or both.
        for (const codes of [['A'], ['A'], ['B'], ['A'], ['B'], ['B'], ['A', 'B'], ['B', 'A'], ['A'], ['B'], ['A'], ['B'], ['A'], ['B']]) {
            const code = memory.frame(codes, (now += 120));
            if (code) {
                acted.push(code);
                memory.done(code, (now += 300));
            }
        }
        expect(acted).toEqual(['A', 'B']);
    });

    it('is not offered on a PC whose browser allows cameras but which has none', async () => {
        mockServer(base());
        Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: vi.fn(), enumerateDevices: async () => [{ kind: 'audioinput' }] } });
        renderApp('/sale');
        await screen.findByRole('searchbox');
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Scan with camera' })).not.toBeInTheDocument());
    });

    it('adds nothing when the lookup comes back after the scanner was closed', async () => {
        let answer: (value: Response) => void = () => undefined;
        const calls = mockServer(base());
        const fetchNow = globalThis.fetch;
        vi.stubGlobal('fetch', vi.fn((url: string, init?: RequestInit) => (url.includes('/pos/search?q=890') ? new Promise<Response>((resolve) => (answer = resolve)) : fetchNow(url, init))));
        giveCamera();
        const user = userEvent.setup();
        renderApp('/sale');

        inView = '8900000000011';
        await user.click(await screen.findByRole('button', { name: 'Scan with camera' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Checking…')).toBeInTheDocument(); // the slow lookup is shown, not silence
        await user.click(within(dialog).getByRole('button', { name: 'Done' }));

        answer(new Response(JSON.stringify({ data: [crocin], matched_barcode: true, barcode_unit: 'pack' }), { status: 200 }));
        await new Promise((resolve) => setTimeout(resolve, 300));
        expect(screen.queryByDisplayValue('1')).not.toBeInTheDocument();
        expect(calls.some((c) => c.path === '/sales/preview')).toBe(false);
        expect(screen.getByRole('searchbox')).toHaveFocus(); // back where a plug-in scanner types
    });

    it('refuses one more than the shelf holds, with the reason', async () => {
        mockServer({ ...base(), 'GET /pos/search': ok({ data: [{ ...crocin, stock_qty: 10 }], matched_barcode: true, barcode_unit: 'pack' }) });
        giveCamera();
        const user = userEvent.setup();
        renderApp('/sale');

        inView = ['8900000000011'];
        await user.click(await screen.findByRole('button', { name: 'Scan with camera' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Added: Crocin 500 Tablet × 1')).toBeInTheDocument();
        inView = '8900000000028'; // another sticker of the same product: the one strip in stock is already on the bill
        expect(await within(dialog).findByText('Crocin 500 Tablet: Only 1 strip can be sold.')).toBeInTheDocument();
        expect(screen.getByDisplayValue('1')).toBeInTheDocument();
    });

    it('is not offered on a counter PC that has no camera and no touch screen', async () => {
        mockServer(base());
        renderApp('/sale');
        await screen.findByRole('searchbox');
        expect(screen.queryByRole('button', { name: 'Scan with camera' })).not.toBeInTheDocument();
    });

    it('adds a pack once however long it is held, says so for an unknown barcode, and switches the camera off when closed', async () => {
        const calls = mockServer(base());
        const getUserMedia = giveCamera();
        const user = userEvent.setup();
        renderApp('/sale');

        inView = '8900000000011';
        await user.click(await screen.findByRole('button', { name: 'Scan with camera' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText('Added: Crocin 500 Tablet × 1')).toBeInTheDocument();
        expect(getUserMedia).toHaveBeenCalledWith(expect.objectContaining({ audio: false }));

        // Still in front of the camera for many more looks: it is not added again.
        await new Promise((resolve) => setTimeout(resolve, 600));
        expect(barcodeLookups(calls)).toBe(1);
        expect(screen.getByDisplayValue('1')).toBeInTheDocument();

        inView = '12345670';
        expect(await within(dialog).findByText('No product has the barcode 12345670')).toBeInTheDocument();
        expect(screen.getByDisplayValue('1')).toBeInTheDocument(); // a number is never searched as a name

        // The first pack comes back into view straight away: it was on screen a moment ago, so it is the same pack.
        inView = '8900000000011';
        await new Promise((resolve) => setTimeout(resolve, 600));
        expect(barcodeLookups(calls)).toBe(1);
        expect(screen.getByDisplayValue('1')).toBeInTheDocument();

        expect(stopCamera).not.toHaveBeenCalled();
        await user.click(within(dialog).getByRole('button', { name: 'Done' }));
        expect(stopCamera).toHaveBeenCalled();
        const lookups = barcodeLookups(calls);
        await new Promise((resolve) => setTimeout(resolve, 400));
        expect(barcodeLookups(calls)).toBe(lookups); // nothing is read after closing
    });

    it('says an item is out of stock inside the scanner and does not add it', async () => {
        mockServer({ ...base(), 'GET /pos/search': ok({ data: [{ ...crocin, stock_qty: 0 }], matched_barcode: true, barcode_unit: 'pack' }) });
        giveCamera();
        const user = userEvent.setup();
        renderApp('/sale');

        inView = '8900000000011';
        await user.click(await screen.findByRole('button', { name: 'Scan with camera' }));
        expect(await within(await screen.findByRole('dialog')).findByText(/Crocin 500 Tablet.*(out of stock|no stock)/i)).toBeInTheDocument();
        expect(screen.queryByDisplayValue('1')).not.toBeInTheDocument();
    });

    it('offers a photo instead when the browser has blocked the camera', async () => {
        mockServer(base());
        giveCamera(vi.fn(async () => Promise.reject(Object.assign(new Error('denied'), { name: 'NotAllowedError' }))));
        const user = userEvent.setup();
        renderApp('/sale');

        await user.click(await screen.findByRole('button', { name: 'Scan with camera' }));
        const dialog = await screen.findByRole('dialog');
        expect(await within(dialog).findByText(/camera is blocked/)).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Take a photo of the barcode' })).toBeInTheDocument();
    });

    it('reads a photo of the barcode on a phone that is not on a secure address', async () => {
        mockServer(base());
        vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('coarse'), addEventListener() {}, removeEventListener() {} }));
        const user = userEvent.setup();
        renderApp('/sale');

        await user.click(await screen.findByRole('button', { name: 'Scan with camera' }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText(/needs a secure \(https\) address/)).toBeInTheDocument();
        const picker = dialog.querySelector<HTMLInputElement>('input[type=file]')!;
        expect(picker).toHaveAttribute('capture', 'environment');

        await user.upload(picker, new File(['x'], 'pack.jpg', { type: 'image/jpeg' }));
        expect(await within(dialog).findByText('No barcode could be read from the photo. Go closer, keep it sharp and try again.')).toBeInTheDocument();

        inView = '8900000000011';
        await user.upload(picker, new File(['x'], 'pack.jpg', { type: 'image/jpeg' }));
        expect(await within(dialog).findByText('Added: Crocin 500 Tablet × 1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('1')).toBeInTheDocument();
    });
});

describe('returns and cancellation', () => {
    const creditNote = {
        id: 9, return_no: 'R26-27/000001', return_date: '2026-11-19', created_at: '2026-11-19T05:00:00+00:00',
        sale: { id: 55, invoice_no: '26-27/000123', sale_date: '2026-11-11' }, customer: null, reason: 'Did not need it', refund_mode: 'refund', refund_method: 'cash', by: 'Ravi',
        items: [{ line_no: 1, product_name: 'Crocin 500 Tablet', batch_no: 'NEAR', unit_code: 'STRIP', qty: 2, bucket: 'unsellable', line_total_paise: 6000, tax_rate_percent: '5.00' }],
        totals: { ...totals(6000), cgst_paise: 143, sgst_paise: 143 },
    };

    it('takes goods back: medicine goes to "not for sale" for a cashier, the amount comes from the server, and the credit note opens', async () => {
        const calls = mockServer({
            ...base(me({ permissions: ['sales.create', 'sales.return'] })),
            'GET /sales/55': ok(bill({ can_return: true })),
            'POST /sales/55/returns/preview': (call) => ok({ lines: [{ line_no: 1, qty: call.body.lines[0].qty, unit_code: 'STRIP', line_total_paise: call.body.lines[0].qty * 3000 }], totals: totals(call.body.lines[0].qty * 3000) })(call),
            'POST /sales/55/returns': () => ({ status: 201, body: creditNote }),
            'GET /returns/9': ok(creditNote),
        });
        const user = userEvent.setup();
        renderApp('/sales/55');

        await user.click(await screen.findByRole('button', { name: 'Return goods' }));
        const dialog = await screen.findByRole('dialog');
        expect(screen.queryByRole('button', { name: 'Cancel bill' })).not.toBeInTheDocument(); // no permission, and not offered by the server

        const confirm = within(dialog).getByRole('button', { name: /Take back and give/ });
        expect(confirm).toBeDisabled(); // nothing ticked yet
        await user.click(within(dialog).getByRole('checkbox', { name: 'Crocin 500 Tablet · Batch no. NEAR · strip' }));
        expect(await within(dialog).findByText('₹60.00')).toBeInTheDocument(); // both strips, priced by the server
        // A cashier cannot put medicine back on the shelf.
        const where = within(dialog).getByLabelText('Where do the goods go?');
        expect(within(where).queryByRole('option', { name: 'Back on the shelf' })).not.toBeInTheDocument();
        expect(where).toHaveValue('unsellable');

        await user.clear(within(dialog).getByLabelText('Taking back Crocin 500 Tablet · Batch no. NEAR · strip'));
        await user.type(within(dialog).getByLabelText('Taking back Crocin 500 Tablet · Batch no. NEAR · strip'), '1');
        expect(within(dialog).getByRole('button', { name: /Take back and give/ })).toBeDisabled(); // the old ₹60 figure can no longer be confirmed
        expect(await within(dialog).findByText('₹30.00')).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Take back and give ₹30.00' })).toBeEnabled();

        await user.click(within(dialog).getByRole('button', { name: 'Take back and give ₹30.00' }));
        expect(await within(dialog).findByText('Write the reason (at least 3 letters).')).toBeInTheDocument();
        await user.type(within(dialog).getByLabelText(/Why is it coming back/), 'Did not need it');
        await user.click(within(dialog).getByRole('button', { name: 'Take back and give ₹30.00' }));

        expect(await screen.findByText('Credit Note', { selector: 'p' })).toBeInTheDocument();
        expect(screen.getByText('R26-27/000001')).toBeInTheDocument();
        const saved = calls.find((c) => c.method === 'POST' && c.path === '/sales/55/returns')!;
        expect(saved.body).toEqual({ lines: [{ line_no: 1, qty: 1, bucket: 'unsellable' }], reason: 'Did not need it', refund_mode: 'refund', refund_method: 'cash', reference_no: null });
        expect(screen.getByText('Return R26-27/000001 saved.')).toBeInTheDocument();
    });

    it('offers to take the return off the udhar when the customer owes money', async () => {
        const customer = { id: 7, name: 'Ramesh Patil', mobile: '9800000010', village: 'Wadgaon', balance_paise: 30000 };
        const calls = mockServer({
            ...base(me({ permissions: ['sales.create', 'sales.return'] })),
            'GET /sales/55': ok(bill({ can_return: true, customer })),
            'POST /sales/55/returns/preview': ok({ lines: [], totals: totals(6000) }),
            'POST /sales/55/returns': () => ({ status: 201, body: { ...creditNote, refund_mode: 'account_credit', refund_method: null, customer: { ...customer, balance_paise: 24000 } } }),
            'GET /returns/9': ok({ ...creditNote, refund_mode: 'account_credit', refund_method: null, customer: { ...customer, balance_paise: 24000 } }),
        });
        const user = userEvent.setup();
        renderApp('/sales/55');

        await user.click(await screen.findByRole('button', { name: 'Return goods' }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByRole('radio', { name: 'Take it off the udhar (₹300.00 owed)' })).toBeChecked(); // the default for someone who owes
        await user.click(within(dialog).getByRole('checkbox', { name: 'Crocin 500 Tablet · Batch no. NEAR · strip' }));
        await user.type(within(dialog).getByLabelText(/Why is it coming back/), 'Wrong strength');
        await user.click(await within(dialog).findByRole('button', { name: 'Take back and reduce udhar by ₹60.00' }));

        await screen.findByText('Credit Note', { selector: 'p' });
        const saved = calls.find((c) => c.method === 'POST' && c.path === '/sales/55/returns')!;
        expect(saved.body.refund_mode).toBe('account_credit');
        expect(saved.body.refund_method).toBeNull();
    });

    it('cancels a wrong bill with a reason and shows it as cancelled', async () => {
        let current = bill({ can_cancel: true, can_return: true });
        const calls = mockServer({
            ...base(me({ permissions: ['sales.create', 'sales.return', 'sales.cancel'] })),
            'GET /sales/55': () => ok(current)(),
            'POST /sales/55/cancel': () => {
                current = bill({ status: 'cancelled', cancelled: { by: 'Owner', at: '2026-11-19T06:00:00+00:00', reason: 'Wrong customer' } });
                return { status: 200, body: { id: 55, status: 'cancelled' } };
            },
        });
        const user = userEvent.setup();
        renderApp('/sales/55');

        await user.click(await screen.findByRole('button', { name: 'Cancel bill' }));
        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Cancel bill 26-27/000123' }));
        expect(await within(dialog).findByText('Write the reason (at least 3 letters).')).toBeInTheDocument();
        await user.type(within(dialog).getByLabelText(/Why is it being cancelled/), 'Wrong customer');
        await user.click(within(dialog).getByRole('button', { name: 'Cancel bill 26-27/000123' }));

        expect(await screen.findByText(/CANCELLED on .* by Owner — Wrong customer/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Cancel bill' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Return goods' })).not.toBeInTheDocument();
        const sent = calls.find((c) => c.method === 'POST' && c.path === '/sales/55/cancel')!;
        expect(sent.body).toEqual({ reason: 'Wrong customer' });
    });

    it('shows a cancelled bill struck through in the history', async () => {
        mockServer({
            ...base(),
            'GET /sales': ok({ data: [
                { id: 55, invoice_no: '26-27/000123', completed_at: '2026-11-19T05:00:00+00:00', buyer_name: null, total_paise: 6000, paid_paise: 6000, credit_paise: 0, methods: ['cash'], status: 'cancelled' },
                { id: 56, invoice_no: '26-27/000124', completed_at: '2026-11-19T05:10:00+00:00', buyer_name: null, total_paise: 3000, paid_paise: 3000, credit_paise: 0, methods: ['cash'], status: 'completed' },
            ], meta: { total: 2, page: 1, last_page: 1 } }),
        });
        renderApp('/sales');
        expect(await screen.findByRole('link', { name: '26-27/000123' })).toHaveClass('line-through');
        expect(screen.getByRole('link', { name: '26-27/000124' })).not.toHaveClass('line-through');
        expect(screen.getAllByText('Cancelled')).toHaveLength(1);
    });
});

describe('unknown barcode on the sale screen', () => {
    it('offers to add the product with that barcode, but only to someone who may add products', async () => {
        mockServer({ ...base(me({ permissions: ['sales.create', 'products.manage'] })), 'GET /pos/search': ok({ data: [], matched_barcode: false, barcode_unit: 'pack' }), 'GET /catalog/options': ok({ units: [], tax_rates: [], categories: [], drug_schedules: [], manufacturers: [], dosage_forms: [] }) });
        const user = userEvent.setup();
        renderApp('/sale');

        await user.type(await screen.findByRole('searchbox'), '8901234567890{Enter}');
        expect(await screen.findByText('No product has the barcode 8901234567890')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Add product with this barcode' }));
        // The product form opens with the barcode filled in (the form itself is tested with the products).
        expect(await screen.findByRole('heading', { name: /Add product|New product/i })).toBeInTheDocument();
        expect(screen.getByDisplayValue('8901234567890')).toBeInTheDocument();
    });

    it('says "not found" only, when the cashier may not add products', async () => {
        mockServer({ ...base(), 'GET /pos/search': ok({ data: [], matched_barcode: false, barcode_unit: 'pack' }) });
        const user = userEvent.setup();
        renderApp('/sale');
        await user.type(await screen.findByRole('searchbox'), '8901234567890{Enter}');
        expect(await screen.findByText('No product has the barcode 8901234567890')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add product with this barcode' })).not.toBeInTheDocument();
    });
});
