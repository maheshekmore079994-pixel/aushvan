import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import i18n from '@/lib/i18n';
import type { Customer, LedgerEntry } from './api';

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
            return new Response(JSON.stringify(result.body), { status: result.status });
        }),
    );
    return calls;
}
const ok = (body: unknown) => () => ({ status: 200, body });

const owner = {
    id: 1, name: 'Rajesh', username: 'owner', locale: 'en', is_pharmacist: false, roles: ['owner'],
    permissions: ['sales.create', 'customers.manage', 'credit.receive', 'credit.reverse', 'sales.price_override'], store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
};
const ramesh: Customer = {
    id: 7, name: 'Ramesh Patil', mobile: '9800000010', village: 'Wadgaon', address: null, notes: null, credit_limit_paise: 200000, balance_paise: 125000,
    last_payment_at: null, days_since_payment: 45, overdue: true,
};
const entries: LedgerEntry[] = [
    { id: 12, date: '2026-11-20', type: 'payment', debit_paise: 0, credit_paise: 20000, balance_after_paise: 125000, note: null, sale_id: null, reversible: true },
    { id: 11, date: '2026-10-13', type: 'opening', debit_paise: 145000, credit_paise: 0, balance_after_paise: 145000, note: 'Opening balance', sale_id: null, reversible: true },
];

function base(): Record<string, Handler> {
    return {
        'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr'] }),
        'GET /auth/me': ok(owner),
        'GET /customers': ok({ data: [ramesh], meta: { total: 1, page: 1, last_page: 1 }, total_owed_paise: 125000, customers_owing: 1 }),
        'GET /customers/7': ok({ ...ramesh, entries }),
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

describe('udhar corrections', () => {
    it('flags overdue accounts and sends the opening balance when adding a customer', async () => {
        const calls = mockServer({
            ...base(),
            'POST /customers': (call) => ({ status: 201, body: { ...ramesh, id: 8, name: call.body.name, balance_paise: call.body.opening_balance_paise } }),
            'GET /customers/8': ok({ ...ramesh, id: 8, name: 'Sunita', balance_paise: 50000, entries: [] }),
        });
        const user = userEvent.setup();
        renderApp('/udhar');

        expect(await screen.findByText('Ramesh Patil')).toBeInTheDocument();
        expect(screen.getByText('Overdue')).toBeInTheDocument();
        expect(screen.getByText(/no payment yet/)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'New customer' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Name'), 'Sunita');
        await user.type(within(dialog).getByLabelText(/Already owes/), '500');
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));
        expect(calls.find((c) => c.method === 'POST' && c.path === '/customers')?.body).toMatchObject({ name: 'Sunita', opening_balance_paise: 50000 });
        expect(await screen.findByRole('heading', { name: 'Sunita' })).toBeInTheDocument();
    });

    it('writes off udhar with a reason and undoes a wrong payment', async () => {
        const calls = mockServer({
            ...base(),
            'POST /customers/7/adjustments': () => ({ status: 201, body: { ...ramesh, balance_paise: 125000 - 5000 } }),
            'POST /customers/7/entries/12/reverse': () => ({ status: 201, body: { ...ramesh, balance_paise: 145000 } }),
        });
        const user = userEvent.setup();
        renderApp('/customers/7');

        expect(await screen.findByRole('heading', { name: 'Ramesh Patil' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Correct' }));
        let dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText(/Amount/), '50');
        await user.type(within(dialog).getByLabelText('Reason'), 'Settled, rest written off');
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));
        const adjust = calls.find((c) => c.path === '/customers/7/adjustments');
        expect(adjust?.body).toEqual({ direction: 'down', amount_paise: 5000, reason: 'Settled, rest written off' });
        expect(adjust?.headers['Idempotency-Key']).toBeTruthy();
        await waitFor(() => expect(screen.getByText('Udhar adjusted.')).toBeInTheDocument());

        const undoButtons = screen.getAllByRole('button', { name: 'Undo this entry' });
        expect(undoButtons).toHaveLength(2); // the payment and the opening balance, not bills
        await user.click(undoButtons[0]);
        dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText(/Undo this Payment received of ₹200.00 in full/)).toBeInTheDocument();
        await user.type(within(dialog).getByLabelText('Reason'), 'Wrong customer');
        await user.click(within(dialog).getByRole('button', { name: 'Undo this entry' }));
        expect(calls.find((c) => c.path === '/customers/7/entries/12/reverse')?.body).toEqual({ reason: 'Wrong customer' });
        await waitFor(() => expect(screen.getByText('Entry reversed.')).toBeInTheDocument());
    });
});
