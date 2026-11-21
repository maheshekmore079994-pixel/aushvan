import { AlertTriangle, Boxes, Clock, PackageX, XCircle, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { useStockSummary } from '@/features/products/api';
import { formatMoney } from '@/lib/money';

type CardProps = { to: string; icon: LucideIcon; label: string; value: string; note?: string; tone: string };

function StatCard({ to, icon: Icon, label, value, note, tone }: CardProps) {
    return (
        <Link to={to} className="flex min-w-0 items-center gap-3 px-4 py-3 transition-colors hover:bg-primary-soft/40">
            <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${tone}`}>
                <Icon className="size-4.5" aria-hidden />
            </span>
            <span className="min-w-0">
                <span className="block truncate text-sm text-ink-soft">{label}</span>
                <span className="block text-lg font-semibold leading-tight">{value}</span>
                {note && <span className="block truncate text-xs text-ink-soft">{note}</span>}
            </span>
        </Link>
    );
}

/** Stock at a glance. Alerts appear only when there is something to act on. */
export function StockOverview() {
    const { t } = useTranslation();
    const summary = useStockSummary();

    if (!summary.data || summary.data.products === 0) return null;
    const s = summary.data;

    const alerts = [
        s.expired_batches > 0 && { to: '/expiry', text: t('dashboard.alert_expired', { count: s.expired_batches }), danger: true },
        s.expiring_30 > 0 && { to: '/expiry', text: t('dashboard.alert_expiring', { count: s.expiring_30 }), danger: false },
        s.schedule_not_set > 0 && { to: '/stock/medical', text: t('dashboard.alert_schedule', { count: s.schedule_not_set }), danger: false },
        (s.ledger_mismatches ?? 0) > 0 && { to: '/products', text: t('dashboard.alert_mismatch', { count: s.ledger_mismatches }), danger: true },
    ].filter((a): a is { to: string; text: string; danger: boolean } => Boolean(a));

    return (
        <section className="flex flex-col gap-3" aria-label={t('dashboard.stock_heading')}>
            {alerts.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {alerts.map((alert) => (
                        <Link
                            key={alert.text}
                            to={alert.to}
                            className={`inline-flex min-h-8 items-center gap-1.5 rounded-full px-3 text-sm font-semibold ${
                                alert.danger ? 'bg-danger-soft text-danger-dark' : 'bg-warning-soft text-warning'
                            }`}
                        >
                            <AlertTriangle className="size-4" aria-hidden />
                            {alert.text}
                        </Link>
                    ))}
                </div>
            )}
            <div className="grid grid-cols-2 divide-line overflow-hidden rounded-xl border border-line bg-white shadow-card max-xl:[&>*:nth-child(-n+2)]:border-b max-xl:[&>*:nth-child(odd)]:border-r xl:grid-cols-4 xl:divide-x">
                <StatCard
                    to="/products"
                    icon={Boxes}
                    tone="bg-primary-soft text-primary-dark"
                    label={t('dashboard.card_products')}
                    value={String(s.products)}
                    note={
                        s.stock_value_paise !== undefined
                            ? t('dashboard.card_stock_value', { value: formatMoney(s.stock_value_paise) })
                            : t('dashboard.card_products_split', { medical: s.medical_products, general: s.general_products })
                    }
                />
                <StatCard to="/products" icon={AlertTriangle} tone="bg-warning-soft text-warning" label={t('dashboard.card_low')} value={String(s.low_stock)} />
                <StatCard to="/products" icon={PackageX} tone="bg-danger-soft text-danger-dark" label={t('dashboard.card_out')} value={String(s.out_of_stock)} />
                <StatCard
                    to="/expiry"
                    icon={s.expired_batches > 0 ? XCircle : Clock}
                    tone={s.expired_batches > 0 ? 'bg-danger-soft text-danger-dark' : 'bg-warning-soft text-warning'}
                    label={t('dashboard.card_expiring')}
                    value={String(s.expiring_90)}
                    note={t('dashboard.card_expired_note', { count: s.expired_batches })}
                />
            </div>
        </section>
    );
}
