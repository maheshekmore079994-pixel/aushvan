import { useQueryClient } from '@tanstack/react-query';
import { Download, HardDrive, Save, ShieldCheck } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Button, Card, PageHeader, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { dashboardKey } from '@/features/dashboard/api';
import { backupsKey, useBackups, type BackupOverview, type BackupRow } from './api';

type Units = { kb: string; mb: string; gb: string };

/** "7.2 GB", "96 KB" — for file sizes and free space (decimal units, as the drive makers print them). */
export function formatBytes(bytes: number, units: Units = { kb: 'KB', mb: 'MB', gb: 'GB' }): string {
    if (bytes >= 1_000_000_000) return `${(bytes / 1_000_000_000).toFixed(1)} ${units.gb}`;
    if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} ${units.mb}`;
    return `${Math.max(1, Math.round(bytes / 1000))} ${units.kb}`;
}

function useBytes() {
    const { t } = useTranslation();
    const units: Units = { kb: t('backup.unit_kb'), mb: t('backup.unit_mb'), gb: t('backup.unit_gb') };
    return (bytes: number) => formatBytes(bytes, units);
}

/** Settings → Backup: take one now, choose the USB/second-disk folder, see what was taken and whether it passed. */
export function BackupPage() {
    const { t, i18n } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const overview = useBackups();
    const bytes = useBytes();
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<BackupRow | null>(null);

    if (overview.isError) return <Alert>{t('app.error_generic')}</Alert>;
    if (overview.isPending) return <Spinner label={t('common.loading')} shape="page" />;
    const data = overview.data;
    const when = (iso: string) => new Date(iso).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' });

    async function backUpNow() {
        setBusy(true);
        setResult(null);
        try {
            const row = await api<BackupRow>('/backups', { method: 'POST', idempotencyKey: newIdempotencyKey() });
            setResult(row);
            if (row.status === 'verified') toast('success', row.folder_path ? t('backup.done_folder') : t('backup.done_local'));
            else toast('error', t('backup.failed'));
            void queryClient.invalidateQueries({ queryKey: backupsKey });
            void queryClient.invalidateQueries({ queryKey: dashboardKey });
        } catch (e) {
            if (e instanceof ApiError && e.code === 'backup_running') toast('error', t('errors.backup_running'));
            else toast('error', e instanceof NetworkError ? t('app.no_connection') : t('app.error_generic'));
        } finally {
            setBusy(false);
        }
    }

    const status = data.status;
    const lastTone = status.last_verified_at === null ? 'danger' : status.due ? 'warning' : 'success';

    return (
        <div className="flex flex-col gap-4">
            <PageHeader title={t('backup.title')} subtitle={t('backup.intro')}>
                <Button onClick={backUpNow} busy={busy} icon={<ShieldCheck className="size-5" aria-hidden />}>
                    {busy ? t('backup.working') : t('backup.now')}
                </Button>
            </PageHeader>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title={t('backup.last_title')}>
                    <div className="flex flex-col gap-2">
                        <p className="flex flex-wrap items-center gap-2">
                            <Badge tone={lastTone}>{status.last_verified_at === null ? t('backup.none_yet') : status.due ? t('backup.overdue') : t('backup.up_to_date')}</Badge>
                            {status.last_verified_at && <span>{when(status.last_verified_at)}</span>}
                        </p>
                        <p className="text-sm text-ink-soft">
                            {status.folder === null
                                ? t('backup.last_folder_unset')
                                : status.last_in_folder_at
                                  ? t('backup.last_in_folder', { when: when(status.last_in_folder_at), folder: status.folder })
                                  : t('backup.never_in_folder', { folder: status.folder })}
                        </p>
                        <p className="text-sm text-ink-soft">{t('backup.kept_local', { count: data.keep_local })}</p>
                    </div>
                    {result && <ResultAlert row={result} />}
                </Card>

                <FolderCard data={data} />
            </div>

            <Card title={t('backup.history')} className="overflow-x-auto p-0">
                {data.backups.length === 0 ? (
                    <p className="p-4 text-ink-soft">{t('backup.no_history')}</p>
                ) : (
                    <table className="w-full min-w-[640px] text-left text-sm">
                        <thead className="border-b border-line bg-surface text-ink-soft">
                            <tr>
                                <th className="px-3 py-2 font-semibold">{t('backup.col_when')}</th>
                                <th className="px-3 py-2 font-semibold">{t('backup.col_result')}</th>
                                <th className="px-3 py-2 font-semibold">{t('backup.col_where')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('backup.col_size')}</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {data.backups.map((row) => (
                                <tr key={row.id} className="align-top">
                                    <td className="px-3 py-2">
                                        <span className="block font-medium">{when(row.created_at)}</span>
                                        <span className="block text-ink-soft">
                                            {t(`backup.kind.${row.kind}`)}
                                            {row.by ? ` · ${row.by}` : ''}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <Badge tone={row.status === 'verified' ? 'success' : 'danger'}>{t(`backup.status.${row.status}`)}</Badge>
                                        {row.error && <span className="block text-danger-dark">{t(`errors.${row.error}`)}</span>}
                                        {row.warning && <span className="block text-warning-dark">{t(`errors.${row.warning}`)}</span>}
                                    </td>
                                    <td className="px-3 py-2 break-all">
                                        {row.folder_path ? row.folder_path : t('backup.this_pc_only')}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 text-right">{bytes(row.size_bytes)}</td>
                                    <td className="px-3 py-2 text-right">
                                        {row.downloadable && (
                                            <a
                                                href={`/api/v1/backups/${row.id}/download`}
                                                className="inline-flex min-h-9 items-center gap-1 rounded-md px-2 font-semibold text-primary hover:bg-primary-soft"
                                            >
                                                <Download className="size-4" aria-hidden />
                                                {t('backup.download')}
                                            </a>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            <Card title={t('backup.restore_title')}>
                <p className="text-sm text-ink-soft">{t('backup.restore_help')}</p>
                <pre className="mt-2 overflow-x-auto rounded-lg bg-surface p-3 text-xs">php artisan backup:restore &quot;{t('backup.restore_file_placeholder')}&quot;</pre>
            </Card>
        </div>
    );
}

function ResultAlert({ row }: { row: BackupRow }) {
    const { t } = useTranslation();
    if (row.status !== 'verified') {
        return (
            <div className="mt-3">
                <Alert>{t('backup.failed')}{row.error ? ` ${t(`errors.${row.error}`)}` : ''}</Alert>
            </div>
        );
    }
    return (
        <div className="mt-3">
            <Alert tone={row.warning ? 'warning' : 'success'}>
                {row.folder_path ? t('backup.done_folder_detail', { folder: row.folder_path }) : t('backup.done_local')}
                {row.warning ? ` ${t(`errors.${row.warning}`)}` : ''}
                {row.counts ? ` ${t('backup.counts_line', { sales: row.counts.sales ?? 0, products: row.counts.products ?? 0, customers: row.counts.customers ?? 0 })}` : ''}
            </Alert>
        </div>
    );
}

/** The server answers a folder problem with a code (errors.<code>); anything else is shown as sent. */
function folderError(error: unknown, t: (key: string) => string): string | undefined {
    if (!(error instanceof ApiError)) return undefined;
    const first = error.first('folder');
    if (!first) return undefined;
    return first.startsWith('backup_') ? t(`errors.${first}`) : first;
}

/** The USB / second-disk folder, with the drives that are plugged in as one-tap choices. */
function FolderCard({ data }: { data: BackupOverview }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const bytes = useBytes();
    const [folder, setFolder] = useState(data.status.folder ?? '');
    const [error, setError] = useState<unknown>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setFolder(data.status.folder ?? '');
    }, [data.status.folder]);

    async function save(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const saved = await api<BackupOverview>('/backups/folder', { method: 'PUT', body: { folder: folder.trim() === '' ? null : folder.trim() } });
            queryClient.setQueryData(backupsKey, saved);
            void queryClient.invalidateQueries({ queryKey: dashboardKey });
            toast('success', t('common.saved'));
        } catch (e) {
            setError(e);
            if (e instanceof NetworkError) toast('error', t('app.no_connection'));
            else if (!(e instanceof ApiError) || e.status !== 422) toast('error', t('app.error_generic'));
        } finally {
            setBusy(false);
        }
    }

    const chosenDrive = data.drives.find((d) => folder.toUpperCase().startsWith(d.path.toUpperCase()));

    return (
        <Card title={t('backup.folder_title')}>
            <form onSubmit={save} className="flex flex-col gap-3">
                <p className="text-sm text-ink-soft">{t('backup.folder_help')}</p>
                {data.drives.length > 0 && (
                    <div className="flex flex-wrap gap-2" role="group" aria-label={t('backup.drives')}>
                        {data.drives.map((drive) => (
                            <button
                                key={drive.path}
                                type="button"
                                onClick={() => setFolder(`${drive.path}AUSHVAN Backups`)}
                                className={`inline-flex min-h-9 items-center gap-1.5 rounded-lg border px-3 text-sm ${
                                    chosenDrive?.path === drive.path ? 'border-primary bg-primary-soft font-semibold' : 'border-line bg-white hover:bg-primary-soft/40'
                                }`}
                            >
                                <HardDrive className="size-4" aria-hidden />
                                {drive.path} · {t('backup.free', { size: bytes(drive.free_bytes) })}
                                {drive.system && <span className="text-ink-soft">({t('backup.system_drive')})</span>}
                            </button>
                        ))}
                    </div>
                )}
                <TextField
                    label={t('backup.folder_label')}
                    value={folder}
                    onChange={setFolder}
                    placeholder="E:\AUSHVAN Backups"
                    error={folderError(error, t)}
                    help={data.status.folder ? (data.status.folder_ok ? t('backup.folder_found') : t('backup.folder_not_found')) : undefined}
                />
                {chosenDrive?.system && <Alert tone="warning">{t('backup.same_disk_warning')}</Alert>}
                <div>
                    <Button type="submit" variant="secondary" busy={busy} icon={<Save className="size-5" aria-hidden />}>
                        {t('common.save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}
