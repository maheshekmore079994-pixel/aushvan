import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from './app';
import { ToastProvider } from './components/toast';
import i18n from './lib/i18n';
import en from './locales/en.json';
import mr from './locales/mr.json';

type Handler = (body: unknown) => { status: number; body: unknown };

/** Fake server: routes are "METHOD /path" keys. Records every call. */
function mockServer(routes: Record<string, Handler>) {
    const calls: { key: string; body: unknown; headers: Record<string, string> }[] = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit = {}) => {
            const key = `${init.method ?? 'GET'} ${url.replace('/api/v1', '')}`;
            const body = init.body ? JSON.parse(init.body as string) : undefined;
            calls.push({ key, body, headers: (init.headers ?? {}) as Record<string, string> });
            const handler = routes[key];
            if (!handler) throw new Error(`Unexpected request: ${key}`);
            const result = handler(body);
            return new Response(result.status === 204 ? null : JSON.stringify(result.body), { status: result.status });
        }),
    );

    return calls;
}

const owner = {
    id: 1,
    name: 'Asha Patil',
    username: 'asha',
    locale: 'en',
    is_pharmacist: false,
    roles: ['owner'],
    permissions: ['users.manage', 'settings.manage'],
    store: { id: 1, name: 'Shree Medical', village: 'Wadgaon' },
};
const cashier = { ...owner, id: 2, name: 'Ravi', username: 'ravi', roles: ['cashier'], permissions: ['sales.create'] };

const shop = {
    name: 'Shree Medical',
    legal_name: null,
    address: null,
    village: 'Wadgaon',
    taluka: null,
    district: null,
    state_code: '27',
    pincode: null,
    phone: null,
    gst_mode: 'unregistered',
    gstin: null,
    settings: { drug_licence_20: null, drug_licence_21: null, drug_licence_20f: null, licence_retention_due: null, bill_footer: null, counter_prefix: '' },
};

function renderApp(path = '/') {
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

describe('translations', () => {
    const keys = (value: unknown, prefix = ''): string[] =>
        typeof value === 'object' && value !== null
            ? Object.entries(value).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k))
            : [prefix];

    it('Marathi has exactly the same keys as English (Hindi is checked in reports.test.tsx)', () => {
        expect(keys(mr).sort()).toEqual(keys(en).sort());
    });
});

describe('first run and sign in', () => {
    it('shows setup on a fresh install and opens the dashboard after it', async () => {
        const calls = mockServer({
            'GET /app/status': () => ({ status: 200, body: { needs_setup: true, languages: ['en', 'mr'] } }),
            'GET /auth/me': () => ({ status: 401, body: { message: 'Unauthenticated.' } }),
            'POST /setup': () => ({ status: 201, body: owner }),
            'GET /store': () => ({ status: 200, body: shop }),
            'GET /users': () => ({ status: 200, body: { data: [] } }),
        });
        const user = userEvent.setup();
        renderApp();

        await user.type(await screen.findByLabelText(/Shop name/), 'Shree Medical');
        await user.type(screen.getByLabelText(/Your name/), 'Asha Patil');
        await user.type(screen.getByLabelText(/Username/), 'asha');
        await user.type(screen.getByLabelText(/^Password$/), 'secret-pass-1');
        await user.type(screen.getByLabelText(/Password again/), 'secret-pass-1');
        await user.click(screen.getByRole('button', { name: 'Create shop' }));

        expect(await screen.findByText('Getting your shop ready')).toBeInTheDocument();
        expect(calls.find((c) => c.key === 'POST /setup')?.body).toMatchObject({ shop_name: 'Shree Medical', username: 'asha', locale: 'en' });
    });

    it('shows a wrong-password message beside the field and clears the password', async () => {
        mockServer({
            'GET /app/status': () => ({ status: 200, body: { needs_setup: false, languages: ['en', 'mr'] } }),
            'GET /auth/me': () => ({ status: 401, body: {} }),
            'POST /auth/login': () => ({ status: 422, body: { message: 'x', errors: { username: ['Wrong username or password.'] } } }),
        });
        const user = userEvent.setup();
        renderApp();

        await user.type(await screen.findByLabelText('Username'), 'asha');
        await user.type(screen.getByLabelText('Password'), 'nope-nope');
        await user.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(await screen.findByText('Wrong username or password.')).toBeInTheDocument();
        expect(screen.getByLabelText('Password')).toHaveValue('');
    });

    it('shows the sign-in screen without a language choice and with the branding line', async () => {
        mockServer({
            'GET /app/status': () => ({ status: 200, body: { needs_setup: false, languages: ['en', 'mr'] } }),
            'GET /auth/me': () => ({ status: 401, body: {} }),
        });
        renderApp();

        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        // The language is a saved per-user choice and applies after sign-in (D-066); nothing to pick here.
        expect(screen.queryByRole('button', { name: 'मराठी' })).not.toBeInTheDocument();
        expect(screen.getAllByText(/Every Store\. Better Managed\./).length).toBeGreaterThan(0); // header line and footer line
    });
});

describe('signed in', () => {
    const base = {
        'GET /app/status': () => ({ status: 200, body: { needs_setup: false, languages: ['en', 'mr'] } }),
        'GET /store': () => ({ status: 200, body: shop }),
    };

    it('hides the Users menu from a cashier and keeps them out of the page', async () => {
        mockServer({ ...base, 'GET /auth/me': () => ({ status: 200, body: cashier }) });
        renderApp('/settings/users');

        expect(await screen.findByText('Getting your shop ready')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();
        expect(screen.getAllByText('Ask the owner to complete this.').length).toBeGreaterThan(0);
    });

    it('marks GST done for an unregistered shop and the address as to do', async () => {
        mockServer({
            ...base,
            'GET /auth/me': () => ({ status: 200, body: owner }),
            'GET /users': () => ({ status: 200, body: { data: [] } }),
        });
        renderApp();

        const gst = (await screen.findByText('GST details')).closest('li')!;
        const address = screen.getByText('Shop address and phone').closest('li')!;
        expect(within(gst).getByText('Done')).toBeInTheDocument();
        expect(within(address).getByText('To do')).toBeInTheDocument();
    });

    it('saves shop details, asking for GSTIN only when registered', async () => {
        const calls = mockServer({
            ...base,
            'GET /auth/me': () => ({ status: 200, body: owner }),
            'PUT /store': (body) => ({ status: 200, body: { ...shop, ...(body as object) } }),
        });
        const user = userEvent.setup();
        renderApp('/settings/shop');

        expect(await screen.findByLabelText(/Shop name/)).toHaveValue('Shree Medical');
        expect(screen.queryByLabelText(/GSTIN/)).not.toBeInTheDocument();

        await user.selectOptions(screen.getByLabelText('GST registration'), 'regular');
        await user.type(screen.getByLabelText(/GSTIN/), '27abcde1234f1z5');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('Saved')).toBeInTheDocument();
        expect(calls.find((c) => c.key === 'PUT /store')?.body).toMatchObject({ gst_mode: 'regular', gstin: '27ABCDE1234F1Z5', phone: null });
    });

    it('adds a user with an idempotency key and shows server field errors', async () => {
        let attempt = 0;
        const calls = mockServer({
            ...base,
            'GET /auth/me': () => ({ status: 200, body: owner }),
            'GET /users': () => ({ status: 200, body: { data: [] } }),
            'POST /users': () =>
                ++attempt === 1
                    ? { status: 422, body: { message: 'x', errors: { username: ['The username has already been taken.'] } } }
                    : { status: 201, body: {} },
        });
        const user = userEvent.setup();
        renderApp('/settings/users');

        await user.click(await screen.findByRole('button', { name: 'Add user' }));
        await user.type(screen.getByLabelText(/^Name/), 'Ravi');
        await user.type(screen.getByLabelText(/^Username/), 'asha');
        await user.type(screen.getByLabelText(/^Password/), 'counter-pass-1');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('The username has already been taken.')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(screen.getByText('Saved')).toBeInTheDocument());

        const posts = calls.filter((c) => c.key === 'POST /users');
        expect(posts).toHaveLength(2);
        expect(posts[0].headers['Idempotency-Key']).toBeTruthy();
        expect(posts[1].headers['Idempotency-Key']).toBe(posts[0].headers['Idempotency-Key']);
        expect(posts[1].body).toMatchObject({ name: 'Ravi', roles: ['cashier'], pin: null, is_active: true });
    });
});
