import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import i18n from '@/lib/i18n';

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

const pharmacist = {
    id: 2, name: 'Asha', username: 'pharmacist', locale: 'en', is_pharmacist: true, roles: ['pharmacist'],
    permissions: ['sales.create', 'medicines.manage', 'prescriptions.view'], preferences: { menu_collapsed: true }, store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
};
const joshi = { id: 3, name: 'Dr. Meena Joshi', reg_no: 'MMC-777', clinic: 'Joshi Nursing Home', address: 'Kalyan West', phone: '9822000112', is_active: true };
const retired = { id: 4, name: 'Dr. Old Timer', reg_no: null, clinic: null, address: null, phone: null, is_active: false };

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

describe('doctors screen', () => {
    it('lists every doctor including hidden ones, adds one, and starts with the saved collapsed menu', async () => {
        const calls = mockServer({
            'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr'] }),
            'GET /auth/me': ok(pharmacist),
            'GET /doctors': ok({ data: [joshi, retired] }),
            'POST /doctors': (call) => ({ status: 201, body: { ...joshi, id: 5, name: call.body.name } }),
        });
        const user = userEvent.setup();
        renderApp('/settings/doctors');

        expect(await screen.findByText('Dr. Meena Joshi')).toBeInTheDocument();
        expect(screen.getByText('Dr. Old Timer')).toBeInTheDocument();
        expect(screen.getByText('Switched off')).toBeInTheDocument();
        expect(calls.find((c) => c.path.startsWith('/doctors'))?.path).toContain('all=1');
        // The saved preference applies: the collapsed menu offers "Expand menu".
        expect(screen.getByRole('button', { name: 'Expand menu' })).toBeInTheDocument();

        await user.type(screen.getByRole('searchbox'), 'meena');
        expect(screen.queryByText('Dr. Old Timer')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Add doctor' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Name'), 'Dr. Anil Patil');
        await user.type(within(dialog).getByLabelText(/Registration no/), 'MMC-1');
        await user.click(within(dialog).getByRole('button', { name: 'Save' }));
        const post = calls.find((c) => c.method === 'POST' && c.path === '/doctors');
        expect(post?.body).toMatchObject({ name: 'Dr. Anil Patil', reg_no: 'MMC-1', is_active: true });
        expect(post?.headers['Idempotency-Key']).toBeTruthy();
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument()); // closes after saving
    });
});
