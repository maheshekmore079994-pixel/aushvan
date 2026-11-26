import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/app';
import { ToastProvider } from '@/components/toast';
import type { Dashboard } from '@/features/dashboard/api';
import i18n from '@/lib/i18n';
import type { BackupOverview, BackupRow } from './api';
import { formatBytes } from './BackupPage';

type Call = { method: string; path: string; body: any };
type Handler = (call: Call) => { status: number; body?: unknown };

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

const me = (permissions: string[]) => ({
    id: 1, name: 'Rajesh More', username: 'owner', locale: 'en', is_pharmacist: false, roles: ['owner'], permissions,
    store: { id: 1, name: 'Jay Mahakali Medical', village: 'Khadavli' },
});
const owner = ['sales.create', 'reports.view', 'settings.manage', 'backup.manage'];

const verified: BackupRow = {
    id: 3, kind: 'manual', file_name: 'AUSHVAN-backup-jay-20261126-101500.zip', size_bytes: 97416, status: 'verified', error: null, warning: null,
    folder_path: 'E:\\AUSHVAN Backups\\AUSHVAN-backup-jay-20261126-101500.zip', downloadable: true,
    counts: { sales: 63, products: 81, customers: 7 }, by: 'Rajesh More', created_at: '2026-11-26T04:45:00+00:00',
};

const overview = (folder: string | null, backups: BackupRow[], due = backups.length === 0): BackupOverview => ({
    status: {
        last_verified_at: backups.find((b) => b.status === 'verified')?.created_at ?? null,
        last_in_folder_at: backups.find((b) => b.folder_path)?.created_at ?? null,
        due,
        auto_wanted: false,
        folder,
        folder_ok: folder !== null,
    },
    drives: [
        { path: 'C:\\', free_bytes: 50_000_000_000, total_bytes: 250_000_000_000, system: true },
        { path: 'E:\\', free_bytes: 7_200_000_000, total_bytes: 16_000_000_000, system: false },
    ],
    keep_local: 7,
    backups,
});

const dashboard = (backup: Dashboard['backup']): Dashboard => ({
    today: '2026-11-26',
    mine: { bills: 0, sales_paise: 0, udhar_paise: 0 },
    udhar: { total_paise: 0, customers: 0, top: [] },
    low_stock: [],
    expiring: [],
    recent_bills: [],
    backup,
});

const base = (): Record<string, Handler> => ({
    'GET /app/status': ok({ needs_setup: false, languages: ['en', 'mr', 'hi'] }),
    'GET /auth/me': ok(me(owner)),
    'GET /store': ok({ name: 'Jay Mahakali Medical', address: 'x', village: 'Khadavli', phone: '9', gst_mode: 'unregistered', gstin: null, settings: { drug_licence_20: 'MH' } }),
    'GET /users': ok({ data: [{ id: 1 }, { id: 2 }], roles: ['owner'] }),
    'GET /stock/summary': ok({ products: 0, medical_products: 0, general_products: 0, low_stock: 0, out_of_stock: 0, expired_batches: 0, expiring_30: 0, expiring_60: 0, expiring_90: 0, schedule_not_set: 0, stock_value_paise: 0, ledger_mismatches: 0 }),
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
    localStorage.clear();
    await i18n.changeLanguage('en');
});

describe('backup screen', () => {
    it('takes a backup, shows the result and the drives to copy it to', async () => {
        let state = overview(null, []);
        const calls = mockServer({
            ...base(),
            'GET /backups': () => ({ status: 200, body: state }),
            'POST /backups': () => {
                state = overview(null, [{ ...verified, folder_path: null }]);
                return { status: 201, body: { ...verified, folder_path: null } };
            },
            'PUT /backups/folder': (call) => {
                state = overview(call.body.folder, state.backups);
                return { status: 200, body: state };
            },
        });
        const user = userEvent.setup();
        renderApp('/settings/backup');

        expect(await screen.findByRole('heading', { name: 'Backup' })).toBeInTheDocument();
        expect(screen.getByText('No backup yet')).toBeInTheDocument();
        expect(screen.getByText('No backup has been taken yet.')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Back up now' }));
        expect(await screen.findByText('Backup made and checked on this computer. It holds 63 bills, 81 products and 7 customers.')).toBeInTheDocument();
        expect(calls.find((c) => c.method === 'POST' && c.path === '/backups')).toBeDefined();
        await waitFor(() => expect(screen.getByText('Up to date')).toBeInTheDocument());
        const table = screen.getByRole('table');
        expect(within(table).getByText('This computer only')).toBeInTheDocument();
        expect(within(table).getByRole('link', { name: 'Download' })).toHaveAttribute('href', '/api/v1/backups/3/download');
        expect(within(table).getByText('97 KB')).toBeInTheDocument();

        // The USB stick: one tap fills the folder; the system disk warns.
        await user.click(screen.getByRole('button', { name: /C:\\/ }));
        expect(screen.getByText(/computer’s own disk/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /E:\\/ }));
        expect(screen.getByLabelText('Folder')).toHaveValue('E:\\AUSHVAN Backups');
        expect(screen.queryByText(/computer’s own disk/)).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ folder: 'E:\\AUSHVAN Backups' }));
        expect(await screen.findByText('Folder found — the next backup will be copied here.')).toBeInTheDocument();
    });

    it('shows a failed backup in red with the reason', async () => {
        const failed: BackupRow = { ...verified, id: 4, status: 'failed', error: 'backup_counts_differ', folder_path: null, downloadable: false };
        mockServer({ ...base(), 'GET /backups': ok(overview('E:\\AUSHVAN Backups', [failed, { ...verified, warning: 'backup_folder_missing', folder_path: null }], true)) });
        renderApp('/settings/backup');

        expect(await screen.findByText('Failed')).toBeInTheDocument();
        expect(screen.getByText('The row counts inside the backup do not match the record of it.')).toBeInTheDocument();
        expect(screen.getByText(/USB stick not plugged in/)).toBeInTheDocument();
        expect(screen.getByText('Older than 2 days')).not.toBeNull();
    });

    it('is not reachable without the permission', async () => {
        mockServer({ ...base(), 'GET /auth/me': ok(me(['sales.create'])), 'GET /dashboard': ok(dashboard(undefined)) });
        renderApp('/settings/backup');
        await waitFor(() => expect(screen.queryByRole('heading', { name: 'Backup' })).not.toBeInTheDocument());
        expect(screen.queryByRole('link', { name: 'Backup' })).not.toBeInTheDocument();
    });

    it('formats sizes for people', () => {
        expect(formatBytes(97416)).toBe('97 KB');
        expect(formatBytes(7_200_000_000)).toBe('7.2 GB');
        expect(formatBytes(2_500_000)).toBe('2.5 MB');
    });
});

describe('dashboard reminder', () => {
    it('warns when no verified backup exists and links to the screen', async () => {
        mockServer({ ...base(), 'GET /dashboard': ok(dashboard({ last_verified_at: null, last_in_folder_at: null, due: true, auto_wanted: false, folder: null, folder_ok: false })) });
        renderApp('/');
        expect(await screen.findByText('No backup has been taken yet. A disk failure would lose everything.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back up now' })).toHaveAttribute('href', '/settings/backup');
    });

    it('takes the automatic backup once and reports where the copy went', async () => {
        let posts = 0;
        mockServer({
            ...base(),
            'GET /dashboard': () => ({
                status: 200,
                body: dashboard({ last_verified_at: null, last_in_folder_at: null, due: posts === 0, auto_wanted: posts === 0, folder: 'E:\\AUSHVAN Backups', folder_ok: true }),
            }),
            'POST /backups/auto': () => {
                posts++;
                return posts === 1 ? { status: 201, body: verified } : { status: 204 };
            },
        });
        renderApp('/');
        expect(await screen.findByText('Backup made and checked; a copy is in E:\\AUSHVAN Backups.')).toBeInTheDocument();
        await waitFor(() => expect(posts).toBe(1));
        expect(screen.queryByText(/No backup has been taken yet/)).not.toBeInTheDocument();
    });
});
