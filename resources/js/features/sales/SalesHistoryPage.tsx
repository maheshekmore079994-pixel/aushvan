import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Alert, Badge, Button, Card, Spinner } from '@/components/ui';
import { formatMoney } from '@/lib/money';
import { useSales } from './api';

export function SalesHistoryPage() {
    const { t, i18n } = useTranslation();
    const [search, setSearch] = useState('');
    const [debounced, setDebounced] = useState('');
    const [date, setDate] = useState('');
    const [page, setPage] = useState(1);
    const sales = useSales(debounced, date, page);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(search.trim()), 250);
        return () => clearTimeout(timer);
    }, [search]);
    useEffect(() => setPage(1), [debounced, date]);

    const time = (iso: string) => new Date(iso).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' });

    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-xl font-semibold tracking-tight">{t('nav.sales')}</h1>
            <div className="flex flex-wrap gap-3">
                <div className="relative min-w-64 flex-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        autoFocus
                        aria-label={t('sales.search')}
                        placeholder={t('sales.search')}
                        className="block min-h-12 w-full rounded-lg border border-line bg-white pl-10 pr-4"
                    />
                </div>
                <input
                    type="date"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    aria-label={t('sales.date')}
                    className="min-h-12 rounded-lg border border-line bg-white px-3"
                />
            </div>

            {sales.isError && <Alert>{t('app.error_generic')}</Alert>}
            {sales.isPending && <Spinner label={t('common.loading')} />}
            {sales.data && sales.data.data.length === 0 && <Card className="text-center text-lg font-semibold">{t('sales.none')}</Card>}

            {sales.data && sales.data.data.length > 0 && (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full min-w-[640px] text-left">
                        <thead className="border-b border-line bg-surface text-sm text-ink-soft">
                            <tr>
                                <th className="px-3 py-2 font-semibold">{t('bill.number')}</th>
                                <th className="px-3 py-2 font-semibold">{t('stock.when')}</th>
                                <th className="px-3 py-2 font-semibold">{t('bill.customer')}</th>
                                <th className="px-3 py-2 font-semibold">{t('sales.paid_by')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('pos.total')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {sales.data.data.map((sale) => (
                                <tr key={sale.id} className="hover:bg-primary-soft/40">
                                    <td className="px-3 py-2">
                                        <Link to={`/sales/${sale.id}`} className={`font-semibold hover:underline ${sale.status === 'cancelled' ? 'text-ink-soft line-through' : 'text-primary-dark'}`}>
                                            {sale.invoice_no}
                                        </Link>
                                        {sale.status === 'cancelled' && (
                                            <span className="ml-2">
                                                <Badge tone="danger">{t('returns.status_cancelled')}</Badge>
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 text-sm">{time(sale.completed_at)}</td>
                                    <td className="px-3 py-2">{sale.buyer_name ?? t('pos.walk_in')}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {sale.methods.map((method) => (
                                                <Badge key={method} tone="success">
                                                    {t(`pay.${method}`)}
                                                </Badge>
                                            ))}
                                            {sale.credit_paise > 0 && (
                                                <Badge tone="warning">
                                                    {t('bill.udhar')} {formatMoney(sale.credit_paise)}
                                                </Badge>
                                            )}
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 text-right font-bold">{formatMoney(sale.total_paise)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}

            {sales.data && sales.data.meta.last_page > 1 && (
                <div className="flex items-center justify-center gap-3">
                    <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {t('common.previous')}
                    </Button>
                    <span className="font-medium">{t('common.page_of', { page: sales.data.meta.page, pages: sales.data.meta.last_page })}</span>
                    <Button variant="secondary" disabled={page >= sales.data.meta.last_page} onClick={() => setPage(page + 1)}>
                        {t('common.next')}
                    </Button>
                </div>
            )}
        </div>
    );
}
