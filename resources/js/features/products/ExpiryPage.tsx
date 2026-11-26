import { clsx } from 'clsx';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Alert, Button, Card, Spinner } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { formatMoney } from '@/lib/money';
import { formatExpiry, formatQty } from '@/lib/qty';
import { useBatches, useStockSummary, useUnitLabel, type BatchRow } from './api';
import { ExpiryBadge, TypeBadge } from './badges';
import { AdjustStockModal, type AdjustAction } from './StockActions';

type Tab = 'expired' | '30' | '60' | '90' | 'unsellable';

/** Stock that has expired or is about to, and stock already set aside as not for sale. */
export function ExpiryPage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const unit = useUnitLabel();
    const summary = useStockSummary();
    const [tab, setTab] = useState<Tab>('expired');
    const [target, setTarget] = useState<{ row: BatchRow; action: AdjustAction } | null>(null);

    const status = tab === 'expired' ? 'expired' : tab === 'unsellable' ? 'unsellable' : 'expiring';
    const batches = useBatches(status, status === 'expiring' ? Number(tab) : undefined);
    const canAdjust = can('stock.adjust');

    const counts: Record<Tab, number | undefined> = {
        expired: summary.data?.expired_batches,
        '30': summary.data?.expiring_30,
        '60': summary.data?.expiring_60,
        '90': summary.data?.expiring_90,
        unsellable: undefined,
    };
    const tabs: Tab[] = ['expired', '30', '60', '90', 'unsellable'];
    const showCost = batches.data?.some((b) => b.value_paise !== undefined) ?? false;
    const totalValue = batches.data?.reduce((sum, b) => sum + (b.value_paise ?? 0), 0) ?? 0;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{t('nav.expiry')}</h1>
                <Button variant="secondary" className="print:hidden" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                    {t('common.print')}
                </Button>
            </div>

            <div className="flex flex-wrap gap-2 print:hidden" role="tablist">
                {tabs.map((value) => (
                    <button
                        key={value}
                        type="button"
                        role="tab"
                        aria-selected={tab === value}
                        onClick={() => setTab(value)}
                        className={clsx(
                            'min-h-8 rounded-full border px-3 text-sm font-medium',
                            tab === value
                                ? value === 'expired'
                                    ? 'border-danger bg-danger text-white'
                                    : 'border-primary bg-primary text-white'
                                : 'border-line bg-white text-ink-soft hover:bg-primary-soft/40',
                        )}
                    >
                        {t(`expiry.tab.${value}`)}
                        {counts[value] !== undefined && <span className="ml-2 rounded-full bg-white/25 px-2">{counts[value]}</span>}
                    </button>
                ))}
            </div>

            <p className="text-ink-soft">{t(`expiry.help.${status}`)}</p>

            {batches.isError && <Alert>{t('app.error_generic')}</Alert>}
            {batches.isPending && <Spinner label={t('common.loading')} />}
            {batches.data && batches.data.length === 0 && (
                <Card className="text-center text-lg font-semibold text-success-dark">{t(`expiry.none.${status}`)}</Card>
            )}

            {batches.data && batches.data.length > 0 && (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full min-w-[760px] text-left">
                        <thead className="border-b border-line bg-surface text-sm text-ink-soft">
                            <tr>
                                <th className="px-3 py-2 font-semibold">{t('products.name')}</th>
                                <th className="px-3 py-2 font-semibold">{t('stock.batch_no')}</th>
                                <th className="px-3 py-2 font-semibold">{t('stock.expiry_label')}</th>
                                <th className="px-3 py-2 font-semibold">{t('stock.quantity')}</th>
                                {showCost && <th className="px-3 py-2 text-right font-semibold">{t('expiry.value')}</th>}
                                {canAdjust && <th className="px-3 py-2 print:hidden" />}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {batches.data.map((row) => {
                                const qty = status === 'unsellable' ? row.qty_unsellable : row.qty_sellable;
                                return (
                                    <tr key={row.id}>
                                        <td className="px-3 py-2">
                                            <Link to={`/products/${row.product_id}`} className="font-semibold text-primary-dark hover:underline">
                                                {row.product_name}
                                            </Link>{' '}
                                            <TypeBadge type={row.product_type} />
                                        </td>
                                        <td className="px-3 py-2">{row.batch_no ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            <span className="mr-2 whitespace-nowrap">{formatExpiry(row.expiry_date, i18n.language)}</span>
                                            <ExpiryBadge status={row.expiry_status} days={row.days_to_expiry} />
                                        </td>
                                        <td className="px-3 py-2 font-semibold">{formatQty(qty, row.pack_size, row.base_unit, row.pricing_unit, unit)}</td>
                                        {showCost && <td className="px-3 py-2 text-right">{formatMoney(row.value_paise ?? 0)}</td>}
                                        {canAdjust && (
                                            <td className="px-3 py-2 text-right print:hidden">
                                                <div className="flex justify-end gap-2">
                                                    {status !== 'unsellable' && (
                                                        <Button variant="secondary" onClick={() => setTarget({ row, action: 'mark_unsellable' })}>
                                                            {t('expiry.set_aside')}
                                                        </Button>
                                                    )}
                                                    {status !== 'expiring' && (
                                                        <Button variant="danger" onClick={() => setTarget({ row, action: 'write_off_expiry' })}>
                                                            {t('expiry.write_off')}
                                                        </Button>
                                                    )}
                                                    {can('purchases.manage') && (
                                                        <Link to={`/purchase-returns/new?search=${encodeURIComponent(row.batch_no ?? row.product_name)}`} className="inline-flex">
                                                            <Button variant="secondary">{t('expiry.return_to_supplier')}</Button>
                                                        </Link>
                                                    )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                        {showCost && (
                            <tfoot className="border-t border-line bg-surface font-semibold">
                                <tr>
                                    <td className="px-3 py-2" colSpan={4}>
                                        {t('expiry.total_value')}
                                    </td>
                                    <td className="px-3 py-2 text-right">{formatMoney(totalValue)}</td>
                                    {canAdjust && <td className="print:hidden" />}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </Card>
            )}

            {target && (
                <AdjustStockModal
                    product={{ id: target.row.product_id, name: target.row.product_name, base_unit: target.row.base_unit, pricing_unit: target.row.pricing_unit, pack_size: target.row.pack_size }}
                    batch={target.row}
                    initialAction={target.action}
                    onClose={() => setTarget(null)}
                />
            )}
        </div>
    );
}
