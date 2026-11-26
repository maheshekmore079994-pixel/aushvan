import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import i18n from '@/lib/i18n';
import type { CashDay, DayFigures, DayState, ExpenseList } from './api';

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

const owner = {
    id: 1, name: 'Rajesh', username: 'owner', locale: 'en', is_pharmacist: false, roles: ['owner'],
    permissions: ['sales.create', 'day.close', 'day.reopen', 'expenses.manage', 'settings.manage'], store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
};

const figures: DayFigures = {
    window_from: '2026-11-27T03:30:00+00:00', window_to: null, opening_cash_paise: 100000,
    cash: { cash_sales: 520000, udhar_received: 60000, supplier_refunds: 0, other_in: 0, refunds: 5000, expenses: 35000, paid_to_suppliers: 200000, other_out: 0, cash_in_paise: 0, cash_out_paise: 0, in_paise: 580000, out_paise: 240000 },
    expected_cash_paise: 440000, non_cash: { upi: 215000, card: 0, bank: 0 }, bills: { count: 23, total_paise: 795000, udhar_paise: 60000 }, movements: [],
};
const openDay: CashDay = {
    id: 5, business_date: '2026-11-27', status: 'open', opened_by: 'Rajesh', opened_at: '2026-11-27T03:30:00+00:00', opening_cash_paise: 100000,
    closed_by: null, closed_at: null, expected_cash_paise: null, counted_cash_paise: null, difference_paise: null, note: null, reopened: null, figures,
};
const closedDay: CashDay = { ...openDay, id: 4, business_date: '2026-11-26', status: 'closed', closed_by: 'Rajesh', closed_at: '2026-11-26T13:00:00+00:00', expected_cash_paise: 100000, counted_cash_paise: 100000, difference_paise: 0, figures: undefined };

function base(state: DayState): Record<string, Handler> {
    return {
        'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr'] }),
        'GET /auth/me': ok(owner),
        'GET /day': ok(state),
        'GET /dashboard': ok({ today: '2026-11-27', mine: { bills: 0, sales_paise: 0, udhar_paise: 0 }, udhar: { total_paise: 0, customers: 0, top: [] }, low_stock: [], expiring: [] }),
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

describe('day closing', () => {
    it('opens the day with the suggested float', async () => {
        const calls = mockServer({
            ...base({ open: null, last_closed: closedDay, suggested_opening_paise: 100000, today: '2026-11-27' }),
            'POST /day/open': () => ({ status: 201, body: openDay }),
        });
        const user = userEvent.setup();
        renderApp('/day');

        expect(await screen.findByText('No day is open.')).toBeInTheDocument();
        expect(screen.getByText(/Last closed/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Open day' }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByLabelText(/Opening cash/)).toHaveValue('1000');
        await user.click(within(dialog).getByRole('button', { name: 'Open day' }));

        const post = calls.find((c) => c.method === 'POST' && c.path === '/day/open');
        expect(post?.body).toEqual({ opening_cash_paise: 100000 });
        expect(post?.headers['Idempotency-Key']).toBeTruthy();
        await waitFor(() => expect(screen.getByText('Day opened.')).toBeInTheDocument());
    });

    it('shows the arithmetic and closes with the counted cash and its difference', async () => {
        const calls = mockServer({
            ...base({ open: openDay, last_closed: closedDay, suggested_opening_paise: 100000, today: '2026-11-27' }),
            'POST /day/close': () => ({ status: 200, body: { ...openDay, status: 'closed', expected_cash_paise: 440000, counted_cash_paise: 439500, difference_paise: -500 } }),
        });
        const user = userEvent.setup();
        renderApp('/day');

        expect(await screen.findByText('Cash that should be there')).toBeInTheDocument();
        expect(screen.getAllByText('₹4,400.00').length).toBeGreaterThan(0); // also inside the kept-mounted close dialog
        expect(screen.getByText('₹5,200.00')).toBeInTheDocument(); // cash sales
        expect(screen.getByText('₹2,150.00')).toBeInTheDocument(); // UPI

        await user.click(screen.getByRole('button', { name: 'Close day' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText(/Counted cash/), '4395');
        expect(within(dialog).getByText('₹5.00 short.')).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Close day' }));

        expect(calls.find((c) => c.path === '/day/close')?.body).toEqual({ counted_cash_paise: 439500, note: null });
        await waitFor(() => expect(screen.getByText('Day closed.')).toBeInTheDocument());
    });

    it('records cash taken out', async () => {
        const calls = mockServer({
            ...base({ open: openDay, last_closed: null, suggested_opening_paise: 0, today: '2026-11-27' }),
            'POST /day/movements': () => ({ status: 201, body: openDay }),
        });
        const user = userEvent.setup();
        renderApp('/day');
        await user.click(await screen.findByRole('button', { name: 'Take cash out' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText(/Amount/), '500');
        await user.type(within(dialog).getByLabelText(/^Note/), 'SBI deposit');
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));
        expect(calls.find((c) => c.path === '/day/movements')?.body).toEqual({ type: 'bank_deposit', amount_paise: 50000, note: 'SBI deposit' });
    });
});

describe('expenses', () => {
    const list: ExpenseList = {
        data: [
            { id: 1, date: '2026-11-27', category: { id: 8, name: 'Tea & snacks', name_local: 'चहा-नाश्ता' }, amount_paise: 2500, method: 'cash', description: 'Tea for staff', reference_no: null, by: 'Rajesh', cancelled: null },
            { id: 2, date: '2026-11-20', category: { id: 2, name: 'Electricity', name_local: 'वीज बिल' }, amount_paise: 180000, method: 'upi', description: null, reference_no: 'UPI9', by: 'Rajesh', cancelled: null },
        ],
        meta: { total: 2, page: 1, last_page: 1 }, from: '2026-11-01', to: '2026-11-27', total_paise: 182500,
        by_category: [{ id: 2, name: 'Electricity', name_local: 'वीज बिल', amount_paise: 180000 }, { id: 8, name: 'Tea & snacks', name_local: 'चहा-नाश्ता', amount_paise: 2500 }],
    };
    const categories = [{ id: 1, name: 'Rent', name_local: 'भाडे', is_active: true }, { id: 8, name: 'Tea & snacks', name_local: 'चहा-नाश्ता', is_active: true }];

    it('lists the period, adds an expense and cancels one with a reason', async () => {
        const calls = mockServer({
            ...base({ open: null, last_closed: null, suggested_opening_paise: 0, today: '2026-11-27' }),
            'GET /expenses': ok(list),
            'GET /expense-categories': ok({ data: categories }),
            'POST /expenses': () => ({ status: 201, body: list.data[0] }),
            'POST /expenses/2/cancel': () => ({ status: 200, body: { ...list.data[1], cancelled: { at: '2026-11-27T06:00:00+00:00', reason: 'Paid twice' } } }),
        });
        const user = userEvent.setup();
        renderApp('/expenses');

        expect(await screen.findByText('₹1,825.00')).toBeInTheDocument();
        expect(screen.getByText('Tea for staff')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Add expense' }));
        const dialog = await screen.findByRole('dialog');
        await user.selectOptions(within(dialog).getByLabelText('Head'), '8');
        await user.type(within(dialog).getByLabelText(/Amount/), '25');
        await user.type(within(dialog).getByLabelText(/^What for/), 'Tea for staff');
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));
        const post = calls.find((c) => c.method === 'POST' && c.path === '/expenses');
        expect(post?.body).toMatchObject({ expense_category_id: 8, amount_paise: 2500, method: 'cash', description: 'Tea for staff' });
        expect(post?.headers['Idempotency-Key']).toBeTruthy();
        await waitFor(() => expect(screen.getByText('Expense saved.')).toBeInTheDocument());

        const cancelButtons = screen.getAllByRole('button', { name: 'Cancel expense' });
        await user.click(cancelButtons[1]);
        const cancelDialog = await screen.findByRole('dialog');
        await user.type(within(cancelDialog).getByLabelText('Reason'), 'Paid twice');
        await user.click(within(cancelDialog).getByRole('button', { name: 'Cancel expense' }));
        expect(calls.find((c) => c.path === '/expenses/2/cancel')?.body).toEqual({ reason: 'Paid twice' });
        await waitFor(() => expect(screen.getByText('Expense cancelled.')).toBeInTheDocument());
    });
});
