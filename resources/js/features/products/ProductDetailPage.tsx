import { ArrowLeft, Pencil, Plus, ScanBarcode } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Alert, Badge, Button, Card, Spinner } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { formatMoney } from '@/lib/money';
import { formatExpiry, formatQty } from '@/lib/qty';
import { useMovements, useProduct, useUnitLabel, type Batch } from './api';
import { ExpiryBadge, ScheduleBadge, StockBadge, TypeBadge } from './badges';
import { AddStockModal, AdjustStockModal } from './StockActions';

function Tile({ label, value, note }: { label: string; value: string; note?: string }) {
    return (
        <div className="rounded-lg border border-line bg-white p-4">
            <p className="text-sm font-medium text-ink-soft">{label}</p>
            <p className="mt-1 text-xl font-bold">{value}</p>
            {note && <p className="text-sm text-ink-soft">{note}</p>}
        </div>
    );
}

export function ProductDetailPage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const navigate = useNavigate();
    const unit = useUnitLabel();
    const id = Number(useParams().id);
    const [search, setSearch] = useSearchParams();
    const product = useProduct(id);
    const movements = useMovements(id);
    // ?stock=1 (from "add product with this barcode") opens the opening-stock dialog for someone allowed to add stock.
    const [adding, setAdding] = useState(search.get('stock') === '1' && can('stock.adjust'));
    const closeAdding = () => {
        setAdding(false);
        if (search.get('stock')) setSearch({}, { replace: true });
    };
    // Back to the sale only when stock was really saved; cancelling stays here, where stock can still be added.
    const afterStock = () => {
        if (search.get('then') === 'sale') navigate('/sale');
    };
    const [adjusting, setAdjusting] = useState<Batch | null>(null);

    if (product.isError) return <Alert>{t('products.not_found')}</Alert>;
    if (product.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const p = product.data;
    const show = (qty: number) => formatQty(qty, p.pack_size, p.base_unit, p.pricing_unit, unit);
    const canEdit = can('products.manage') || (p.type === 'medical' && can('medicines.manage'));
    const canAdjust = can('stock.adjust');
    const showCost = p.batches.some((b) => b.cost_paise !== undefined);
    const liveBatches = p.batches.filter((b) => b.qty_sellable > 0 || b.qty_unsellable > 0);
    const dateTime = (iso: string) => new Date(iso).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' });

    return (
        <div className="flex flex-col gap-4">
            <Link to="/products" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline">
                <ArrowLeft className="size-4" aria-hidden />
                {t('nav.products')}
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">{p.name}</h1>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-ink-soft">
                        <TypeBadge type={p.type} />
                        {p.generic_name && <span>{[p.generic_name, p.strength].filter(Boolean).join(' ')}</span>}
                        {p.name_local && <span>{p.name_local}</span>}
                        <ScheduleBadge code={p.schedule} isSet={p.schedule_set} />
                        {!p.is_active && <Badge>{t('common.inactive')}</Badge>}
                        <span className="text-sm">{p.sku}</span>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can('products.manage') && (
                        <Button variant="secondary" onClick={() => navigate(`/labels?product=${p.id}`)} icon={<ScanBarcode className="size-4" aria-hidden />}>
                            {t('labels.print')}
                        </Button>
                    )}
                    {canEdit && (
                        <Button variant="secondary" onClick={() => navigate(`/products/${p.id}/edit`)} icon={<Pencil className="size-4" aria-hidden />}>
                            {t('common.edit')}
                        </Button>
                    )}
                    {canAdjust && (
                        <Button variant="success" onClick={() => setAdding(true)} icon={<Plus className="size-5" aria-hidden />}>
                            {t('stock.add_button')}
                        </Button>
                    )}
                </div>
            </div>

            {p.type === 'medical' && !p.schedule_set && <Alert tone="warning">{t('products.schedule_not_set_help')}</Alert>}
            {p.expired_qty > 0 && <Alert>{t('stock.expired_alert', { qty: show(p.expired_qty) })}</Alert>}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-lg border border-line bg-white p-4">
                    <p className="text-sm font-medium text-ink-soft">{t('stock.in_stock')}</p>
                    <p className="mt-1 text-xl font-bold">{show(p.stock_qty)}</p>
                    <StockBadge status={p.stock_status} />
                </div>
                <Tile
                    label={t('products.selling_price')}
                    value={formatMoney(p.selling_price_paise)}
                    note={`/ ${unit(p.pricing_unit)}${p.mrp_paise > 0 ? ` · MRP ${formatMoney(p.mrp_paise)}` : ''}`}
                />
                <Tile label={t('products.min_stock')} value={p.min_stock_qty > 0 ? show(p.min_stock_qty) : '—'} note={p.tax_rate} />
                <Tile label={t('stock.not_for_sale')} value={show(p.unsellable_qty)} note={p.rack_location ? `${t('products.rack')}: ${p.rack_location}` : undefined} />
            </div>

            <Card title={t('stock.batches')} className="overflow-x-auto">
                {liveBatches.length === 0 ? (
                    <p className="text-ink-soft">{t('stock.no_stock')}</p>
                ) : (
                    <table className="w-full min-w-[640px] text-left">
                        <thead className="border-b border-line text-sm text-ink-soft">
                            <tr>
                                <th className="py-2 pr-4 font-semibold">{t('stock.batch_no')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('stock.expiry_label')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('stock.for_sale')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('stock.not_for_sale')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">MRP</th>
                                {showCost && <th className="py-2 pr-4 text-right font-semibold">{t('stock.cost')}</th>}
                                {canAdjust && <th className="py-2" />}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {liveBatches.map((batch) => (
                                <tr key={batch.id} className={batch.expiry_status === 'expired' ? 'bg-danger-soft/50' : undefined}>
                                    <td className="py-3 pr-4 font-medium">{batch.batch_no ?? '—'}</td>
                                    <td className="py-3 pr-4">
                                        <span className="mr-2 whitespace-nowrap">{formatExpiry(batch.expiry_date, i18n.language)}</span>
                                        <ExpiryBadge status={batch.expiry_status} days={batch.days_to_expiry} />
                                    </td>
                                    <td className="py-3 pr-4 font-semibold">{show(batch.qty_sellable)}</td>
                                    <td className="py-3 pr-4">{batch.qty_unsellable > 0 ? show(batch.qty_unsellable) : '—'}</td>
                                    <td className="py-3 pr-4 text-right">{batch.mrp_paise > 0 ? formatMoney(batch.mrp_paise) : '—'}</td>
                                    {showCost && <td className="py-3 pr-4 text-right">{formatMoney(batch.cost_paise ?? 0)}</td>}
                                    {canAdjust && (
                                        <td className="py-3 text-right">
                                            <Button variant="secondary" onClick={() => setAdjusting(batch)}>
                                                {t('stock.adjust')}
                                            </Button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            <Card title={t('stock.history')} className="overflow-x-auto">
                {movements.isPending && <Spinner label={t('common.loading')} />}
                {movements.data && movements.data.data.length === 0 && <p className="text-ink-soft">{t('stock.no_history')}</p>}
                {movements.data && movements.data.data.length > 0 && (
                    <table className="w-full min-w-[640px] text-left">
                        <thead className="border-b border-line text-sm text-ink-soft">
                            <tr>
                                <th className="py-2 pr-4 font-semibold">{t('stock.when')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('stock.what')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('stock.batch_no')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">{t('stock.change')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">{t('stock.balance')}</th>
                                <th className="py-2 font-semibold">{t('stock.by')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {movements.data.data.map((m) => (
                                <tr key={m.id}>
                                    <td className="whitespace-nowrap py-2 pr-4 text-sm">{dateTime(m.at)}</td>
                                    <td className="py-2 pr-4">
                                        {t(`stock.movement.${m.type}`, { defaultValue: m.type })}
                                        {m.bucket === 'unsellable' && <span className="text-ink-soft"> ({t('stock.not_for_sale')})</span>}
                                        {m.note && <span className="block text-sm text-ink-soft">{m.note}</span>}
                                    </td>
                                    <td className="py-2 pr-4">{m.batch_no ?? '—'}</td>
                                    <td className={`py-2 pr-4 text-right font-semibold ${m.qty_delta < 0 ? 'text-danger' : 'text-success-dark'}`}>
                                        {m.qty_delta > 0 ? '+' : ''}
                                        {m.qty_delta}
                                    </td>
                                    <td className="py-2 pr-4 text-right">{m.balance_after}</td>
                                    <td className="py-2 text-sm">{m.user}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
                {movements.data && movements.data.data.length > 0 && (
                    <p className="mt-2 text-sm text-ink-soft">{t('stock.history_unit', { unit: unit(p.base_unit) })}</p>
                )}
            </Card>

            <AddStockModal product={p} open={adding} onClose={closeAdding} onSaved={afterStock} />
            <AdjustStockModal product={p} batch={adjusting} onClose={() => setAdjusting(null)} />
        </div>
    );
}
