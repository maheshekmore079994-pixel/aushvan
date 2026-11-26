import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { ArrowLeft, Printer, Search, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Alert, Badge, Button, Card, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useUnitLabel } from '@/features/products/api';
import { PAYMENT_METHODS, type PaymentMethod } from '@/features/sales/api';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { formatExpiry, formatQty } from '@/lib/qty';
import { purchaseKeys, useDebitNote, useLots, useSuppliers, type Lot } from './api';

type ChosenLine = { lot: Lot; bucket: 'sellable' | 'unsellable'; qty: string };

/** Send stock back to a supplier: pick the batches, say why, settle by ledger or by money received. */
export function PurchaseReturnPage() {
    const { t, i18n } = useTranslation();
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const unit = useUnitLabel();
    const [params] = useSearchParams();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [supplierId, setSupplierId] = useState<number | null>(params.get('supplier') ? Number(params.get('supplier')) : null);
    const [search, setSearch] = useState(params.get('search') ?? ''); // the Expiry screen sends the batch here
    const [expiredOnly, setExpiredOnly] = useState(false);
    const [lines, setLines] = useState<ChosenLine[]>([]);
    const [reason, setReason] = useState('');
    const [settlement, setSettlement] = useState<'reduce_balance' | 'refund_received'>('reduce_balance');
    const [method, setMethod] = useState<PaymentMethod>('cash');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [unsure, setUnsure] = useState(false);
    const suppliers = useSuppliers('', false);
    const lots = useLots(search, supplierId, expiredOnly);

    const add = (lot: Lot) => {
        if (lines.some((l) => l.lot.id === lot.id)) return;
        const bucket = lot.qty_unsellable > 0 && lot.qty_sellable === 0 ? 'unsellable' : 'sellable';
        setLines([...lines, { lot, bucket, qty: String(bucket === 'sellable' ? lot.qty_sellable : lot.qty_unsellable) }]);
        if (supplierId === null && lot.supplier_id) setSupplierId(lot.supplier_id);
    };
    const update = (id: number, patch: Partial<ChosenLine>) => setLines(lines.map((l) => (l.lot.id === id ? { ...l, ...patch } : l)));
    const estimate = lines.reduce((sum, l) => sum + (/^\d+$/.test(l.qty) ? Math.round(Number(l.lot.cost_rate) * Number(l.qty)) : 0), 0);

    async function submit() {
        if (supplierId === null) return setMessage(t('purchases.choose_supplier'));
        if (lines.length === 0) return setMessage(t('purchases.return_pick'));
        if (reason.trim().length < 3) return setMessage(t('validation.reason'));
        for (const l of lines) {
            const max = l.bucket === 'sellable' ? l.lot.qty_sellable : l.lot.qty_unsellable;
            if (!/^\d+$/.test(l.qty) || Number(l.qty) < 1 || Number(l.qty) > max) return setMessage(t('stock.only_available', { qty: formatQty(max, l.lot.pack_size, l.lot.base_unit, l.lot.pricing_unit, unit) }));
        }
        setBusy(true);
        setMessage(null);
        try {
            const note = await api<{ id: number }>('/purchase-returns', {
                method: 'POST',
                idempotencyKey: idempotencyKey.current,
                body: {
                    supplier_id: supplierId,
                    reason: reason.trim(),
                    settlement,
                    refund_method: settlement === 'refund_received' ? method : null,
                    lines: lines.map((l) => ({ batch_id: l.lot.id, bucket: l.bucket, qty_base: Number(l.qty) })),
                },
            });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.suppliers });
            void queryClient.invalidateQueries({ queryKey: ['products'] });
            toast('success', t('purchases.return_done'));
            navigate(`/purchase-returns/${note.id}`, { replace: true });
        } catch (e) {
            if (e instanceof NetworkError) setUnsure(true);
            setMessage(
                e instanceof NetworkError
                    ? t('udhar.unsure')
                    : e instanceof ApiError && e.code
                      ? t(`errors.${e.code}`, { defaultValue: t('app.error_generic') })
                      : e instanceof ApiError && e.status === 422
                        ? t('validation.check_fields')
                        : t('app.error_generic'),
            );
        } finally {
            setBusy(false);
        }
    }

    const chip = (active: boolean) =>
        clsx('min-h-8 rounded-full border px-3 text-sm font-medium', active ? 'border-primary bg-primary text-white' : 'border-line bg-white text-ink-soft');

    return (
        <div className="flex flex-col gap-4">
            <Link to="/purchases" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline">
                <ArrowLeft className="size-4" aria-hidden />
                {t('nav.purchases')}
            </Link>
            <h1 className="text-xl font-semibold tracking-tight">{t('purchases.return_title')}</h1>
            <p className="text-ink-soft">{t('purchases.return_help')}</p>
            {message && <Alert>{message}</Alert>}

            <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
                <Card title={t('purchases.return_pick_title')}>
                    <SelectField
                        label={t('purchases.supplier')}
                        value={supplierId === null ? '' : String(supplierId)}
                        onChange={(v) => setSupplierId(v === '' ? null : Number(v))}
                        options={[{ value: '', label: t('purchases.any_supplier') }, ...(suppliers.data?.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))]}
                    />
                    <div className="relative mt-3">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            aria-label={t('purchases.lot_search')}
                            placeholder={t('purchases.lot_search')}
                            className="block min-h-11 w-full rounded-lg border border-line bg-white pl-10 pr-4"
                        />
                    </div>
                    <div className="mt-2 flex gap-2">
                        <button type="button" className={chip(!expiredOnly)} aria-pressed={!expiredOnly} onClick={() => setExpiredOnly(false)}>
                            {t('stock.any_stock')}
                        </button>
                        <button type="button" className={chip(expiredOnly)} aria-pressed={expiredOnly} onClick={() => setExpiredOnly(true)}>
                            {t('expiry.tab.expired')}
                        </button>
                    </div>
                    {lots.isPending && <Spinner label={t('common.loading')} />}
                    {lots.data && lots.data.length === 0 && <p className="mt-3 text-ink-soft">{t('purchases.no_lots')}</p>}
                    <ul className="mt-3 max-h-96 divide-y divide-line overflow-y-auto">
                        {(lots.data ?? []).map((lot) => (
                            <li key={lot.id}>
                                <button
                                    type="button"
                                    onClick={() => add(lot)}
                                    disabled={lines.some((l) => l.lot.id === lot.id)}
                                    className="flex w-full flex-wrap items-center justify-between gap-2 px-1 py-2 text-left hover:bg-surface disabled:opacity-50"
                                >
                                    <span>
                                        <span className="font-semibold">{lot.product_name}</span>
                                        <span className="ml-2 text-sm text-ink-soft">
                                            {[lot.batch_no, lot.expiry_date && formatExpiry(lot.expiry_date, i18n.language)].filter(Boolean).join(' · ')}
                                        </span>
                                        {lot.expired && <Badge tone="danger">{t('expiry.tab.expired')}</Badge>}
                                    </span>
                                    <span className="text-sm">
                                        {formatQty(lot.qty_sellable, lot.pack_size, lot.base_unit, lot.pricing_unit, unit)}
                                        {lot.qty_unsellable > 0 && <span className="text-ink-soft"> + {t('stock.not_for_sale')} {formatQty(lot.qty_unsellable, lot.pack_size, lot.base_unit, lot.pricing_unit, unit)}</span>}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card title={t('purchases.return_lines')}>
                    {lines.length === 0 && <p className="text-ink-soft">{t('purchases.return_pick')}</p>}
                    <ul className="divide-y divide-line">
                        {lines.map((l) => (
                            <li key={l.lot.id} className="flex flex-wrap items-end gap-3 py-3">
                                <div className="min-w-40 flex-1">
                                    <p className="font-semibold">{l.lot.product_name}</p>
                                    <p className="text-sm text-ink-soft">{[l.lot.batch_no, l.lot.expiry_date && formatExpiry(l.lot.expiry_date, i18n.language)].filter(Boolean).join(' · ')}</p>
                                </div>
                                {l.lot.qty_sellable > 0 && l.lot.qty_unsellable > 0 && (
                                    <SelectField
                                        label={t('stock.take_from')}
                                        value={l.bucket}
                                        onChange={(v) => update(l.lot.id, { bucket: v as ChosenLine['bucket'], qty: String(v === 'sellable' ? l.lot.qty_sellable : l.lot.qty_unsellable) })}
                                        options={[
                                            { value: 'sellable', label: t('stock.for_sale') },
                                            { value: 'unsellable', label: t('stock.not_for_sale') },
                                        ]}
                                    />
                                )}
                                <TextField label={t('stock.quantity_in', { unit: unit(l.lot.base_unit) })} value={l.qty} onChange={(v) => update(l.lot.id, { qty: v.replace(/\D/g, '') })} inputMode="numeric" className="w-28" />
                                <Button variant="ghost" onClick={() => setLines(lines.filter((x) => x.lot.id !== l.lot.id))} aria-label={t('pos.remove', { name: '' }).trim()} icon={<Trash2 className="size-4" aria-hidden />} />
                            </li>
                        ))}
                    </ul>
                    {lines.length > 0 && <p className="mt-2 text-sm text-ink-soft">{t('purchases.return_estimate', { amount: formatMoney(estimate) })}</p>}

                    <div className="mt-4 flex flex-col gap-3 border-t border-line pt-4">
                        <TextField label={t('stock.reason')} value={reason} onChange={setReason} placeholder={t('purchases.return_reason_placeholder')} maxLength={300} disabled={unsure} />
                        <SelectField
                            label={t('purchases.settlement')}
                            value={settlement}
                            onChange={(v) => setSettlement(v as typeof settlement)}
                            disabled={unsure}
                            options={[
                                { value: 'reduce_balance', label: t('purchases.settle.reduce_balance') },
                                { value: 'refund_received', label: t('purchases.settle.refund_received') },
                            ]}
                        />
                        {settlement === 'refund_received' && (
                            <SelectField label={t('sales.paid_by')} value={method} onChange={(v) => setMethod(v as PaymentMethod)} disabled={unsure} options={PAYMENT_METHODS.map((m) => ({ value: m, label: t(`pay.${m}`) }))} />
                        )}
                        <Button variant="danger" size="lg" busy={busy} onClick={() => void submit()} disabled={lines.length === 0}>
                            {busy ? t('common.saving') : t('purchases.return_submit')}
                        </Button>
                    </div>
                </Card>
            </div>
        </div>
    );
}

/** A saved debit note. */
export function DebitNotePage() {
    const { t, i18n } = useTranslation();
    const id = Number(useParams().id);
    const note = useDebitNote(id);

    if (note.isError) return <Alert>{t('purchases.not_found')}</Alert>;
    if (note.isPending) return <Spinner label={t('common.loading')} shape="page" />;
    const n = note.data;
    const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });

    return (
        <div className="flex flex-col gap-4">
            <Link to={`/suppliers/${n.supplier.id}`} className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline print:hidden">
                <ArrowLeft className="size-4" aria-hidden />
                {n.supplier.name}
            </Link>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">{t('purchases.debit_note')} {n.return_no}</h1>
                    <p className="text-ink-soft">
                        {n.supplier.name} · {day(n.return_date)} · {t('purchases.entered_by', { name: n.by })}
                    </p>
                    <p className="text-sm text-ink-soft">
                        {t('stock.reason')}: {n.reason} · {t(`purchases.settle.${n.settlement}`)}
                        {n.refund_method && ` (${t(`pay.${n.refund_method}`)})`}
                    </p>
                </div>
                <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />} className="print:hidden">
                    {t('common.print')}
                </Button>
            </div>
            <Card className="overflow-x-auto p-0">
                <table className="w-full min-w-[560px] text-left text-sm">
                    <thead className="border-b border-line text-ink-soft">
                        <tr>
                            <th className="px-3 py-2 font-semibold">{t('purchases.product')}</th>
                            <th className="px-3 py-2 font-semibold">{t('stock.batch_no')}</th>
                            <th className="px-3 py-2 font-semibold">{t('stock.take_from')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.qty')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.gst')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('bill.amount')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {n.items.map((item) => (
                            <tr key={item.line_no}>
                                <td className="px-3 py-2 font-medium">{item.product_name}</td>
                                <td className="px-3 py-2">{item.batch_no ?? '—'}</td>
                                <td className="px-3 py-2">{item.bucket === 'sellable' ? t('stock.for_sale') : t('stock.not_for_sale')}</td>
                                <td className="px-3 py-2 text-right">{item.qty_base}</td>
                                <td className="px-3 py-2 text-right">{Number(item.tax_rate_percent)}%</td>
                                <td className="px-3 py-2 text-right font-semibold">{formatMoney(item.line_total_paise, false)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t border-line font-semibold">
                            <td className="px-3 py-2" colSpan={5}>
                                {t('pos.total')}
                            </td>
                            <td className="px-3 py-2 text-right">{formatMoney(n.totals.total_paise)}</td>
                        </tr>
                    </tfoot>
                </table>
            </Card>
        </div>
    );
}
