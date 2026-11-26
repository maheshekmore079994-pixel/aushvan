import { clsx } from 'clsx';
import { Plus, Search, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Alert, Button, Card, EmptyState, Spinner } from '@/components/ui';
import { formatMoney } from '@/lib/money';
import { usePurchases } from './api';
import { StatusBadge } from './SupplierPage';

const FILTERS = ['', 'draft', 'due', 'confirmed', 'cancelled'] as const;

/** Every supplier invoice entered: drafts to finish, confirmed ones, and what is still to be paid. */
export function PurchasesPage() {
    const { t, i18n } = useTranslation();
    const [status, setStatus] = useState<(typeof FILTERS)[number]>('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const purchases = usePurchases(status, search, page);
    const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });

    const chip = (active: boolean) =>
        clsx('min-h-8 rounded-full border px-3 text-sm font-medium', active ? 'border-primary bg-primary text-white' : 'border-line bg-white text-ink-soft');

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{t('nav.purchases')}</h1>
                <div className="flex gap-2">
                    <Link to="/purchase-returns/new" className="inline-flex">
                        <Button variant="secondary" icon={<Undo2 className="size-5" aria-hidden />}>
                            {t('purchases.return')}
                        </Button>
                    </Link>
                    <Link to="/purchases/new" className="inline-flex">
                        <Button icon={<Plus className="size-5" aria-hidden />}>{t('purchases.new')}</Button>
                    </Link>
                </div>
            </div>

            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                <input
                    type="search"
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    aria-label={t('purchases.search')}
                    placeholder={t('purchases.search')}
                    className="block min-h-12 w-full rounded-lg border border-line bg-white pl-10 pr-4"
                />
            </div>
            <div className="flex flex-wrap gap-2">
                {FILTERS.map((f) => (
                    <button
                        key={f || 'all'}
                        type="button"
                        className={chip(status === f)}
                        aria-pressed={status === f}
                        onClick={() => {
                            setStatus(f);
                            setPage(1);
                        }}
                    >
                        {t(`purchases.filter.${f || 'all'}`)}
                    </button>
                ))}
            </div>

            {purchases.isError && <Alert>{t('app.error_generic')}</Alert>}
            {purchases.isPending && <Spinner label={t('common.loading')} />}
            {purchases.data && purchases.data.data.length === 0 && (
                <EmptyState title={t('purchases.none_yet')}>
                    <p>{t('purchases.none_help')}</p>
                </EmptyState>
            )}
            {purchases.data && purchases.data.data.length > 0 && (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full min-w-[640px] text-left">
                        <thead className="border-b border-line text-sm text-ink-soft">
                            <tr>
                                <th className="px-3 py-2 font-semibold">{t('purchases.col_no')}</th>
                                <th className="px-3 py-2 font-semibold">{t('sales.date')}</th>
                                <th className="px-3 py-2 font-semibold">{t('purchases.supplier')}</th>
                                <th className="px-3 py-2 font-semibold">{t('purchases.invoice_no')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('pos.total')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('purchases.col_due')}</th>
                                <th className="px-3 py-2 font-semibold">{t('common.status')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {purchases.data.data.map((p) => (
                                <tr key={p.id} className="hover:bg-surface">
                                    <td className="px-3 py-2">
                                        <Link to={`/purchases/${p.id}`} className="font-semibold text-primary-dark hover:underline">
                                            {p.purchase_no ?? t('purchases.status.draft')}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2">{day(p.received_date)}</td>
                                    <td className="px-3 py-2">{p.supplier.name}</td>
                                    <td className="px-3 py-2">{p.supplier_invoice_no ?? '—'}</td>
                                    <td className="px-3 py-2 text-right font-semibold">{formatMoney(p.total_paise)}</td>
                                    <td className="px-3 py-2 text-right">{p.status === 'confirmed' && p.total_paise > p.paid_paise ? formatMoney(p.total_paise - p.paid_paise) : ''}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={p.status} paid={p.paid_paise} total={p.total_paise} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {purchases.data.meta.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t border-line px-3 py-2 text-sm">
                            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                {t('common.previous')}
                            </Button>
                            <span className="text-ink-soft">{t('common.page_of', { page, total: purchases.data.meta.last_page })}</span>
                            <Button variant="secondary" disabled={page >= purchases.data.meta.last_page} onClick={() => setPage(page + 1)}>
                                {t('common.next')}
                            </Button>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}
