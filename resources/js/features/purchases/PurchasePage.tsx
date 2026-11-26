import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Banknote, Ban, Pencil, Printer } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { Alert, Button, Card, Modal, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { useUnitLabel } from '@/features/products/api';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { formatExpiry } from '@/lib/qty';
import { purchaseKeys, usePurchase, type Purchase } from './api';
import { PayForm, StatusBadge } from './SupplierPage';

/** A saved purchase: lines, taxes, payments; edit while a draft, pay or cancel once confirmed. */
export function PurchasePage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const id = Number(useParams().id);
    const purchase = usePurchase(id);
    const [paying, setPaying] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const unit = useUnitLabel();

    if (purchase.isError) return <Alert>{t('purchases.not_found')}</Alert>;
    if (purchase.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const p = purchase.data;
    const day = (iso: string | null) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' }) : '—');
    const due = p.total_paise - p.paid_paise;
    const tax = p.totals.cgst_paise + p.totals.sgst_paise + p.totals.igst_paise + p.totals.cess_paise;

    return (
        <div className="flex flex-col gap-4">
            <Link to="/purchases" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline print:hidden">
                <ArrowLeft className="size-4" aria-hidden />
                {t('nav.purchases')}
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                        {p.purchase_no ?? t('purchases.draft_title')}
                        <StatusBadge status={p.status} paid={p.paid_paise} total={p.total_paise} />
                    </h1>
                    <p className="text-ink-soft">
                        <Link to={`/suppliers/${p.supplier.id}`} className="font-medium text-primary-dark hover:underline">
                            {p.supplier.name}
                        </Link>
                        {p.supplier_invoice_no && <span> · {t('purchases.invoice_no')} {p.supplier_invoice_no}</span>}
                        {p.invoice_date && <span> · {day(p.invoice_date)}</span>}
                    </p>
                    <p className="text-sm text-ink-soft">
                        {t('purchases.received_on', { date: day(p.received_date) })} · {t('purchases.entered_by', { name: p.by })}
                        {p.due_date && p.status === 'confirmed' && due > 0 && <span> · {t('purchases.due_by', { date: day(p.due_date) })}</span>}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                        {t('common.print')}
                    </Button>
                    {can('purchases.manage') && p.status === 'draft' && (
                        <Link to={`/purchases/${p.id}/edit`} className="inline-flex">
                            <Button icon={<Pencil className="size-4" aria-hidden />}>{t('purchases.continue_draft')}</Button>
                        </Link>
                    )}
                    {can('purchases.manage') && p.status === 'confirmed' && (
                        <>
                            <Button variant="secondary" onClick={() => setCancelling(true)} icon={<Ban className="size-4" aria-hidden />}>
                                {t('purchases.cancel')}
                            </Button>
                            {due > 0 && (
                                <Button variant="success" onClick={() => setPaying(true)} icon={<Banknote className="size-5" aria-hidden />}>
                                    {t('suppliers.pay')}
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </div>

            {p.cancelled && (
                <Alert tone="danger">
                    {t('purchases.cancelled_line', { reason: p.cancelled.reason })}
                </Alert>
            )}
            {p.status === 'confirmed' && p.invoice_total_paise !== null && p.invoice_total_paise !== p.total_paise && (
                <Alert tone="warning">{t('purchases.total_differs', { paper: formatMoney(p.invoice_total_paise), ours: formatMoney(p.total_paise) })}</Alert>
            )}

            <Card className="overflow-x-auto p-0">
                <table className="w-full min-w-[720px] text-left text-sm">
                    <thead className="border-b border-line text-ink-soft">
                        <tr>
                            <th className="px-3 py-2 font-semibold">#</th>
                            <th className="px-3 py-2 font-semibold">{t('purchases.product')}</th>
                            <th className="px-3 py-2 font-semibold">{t('stock.batch_no')}</th>
                            <th className="px-3 py-2 font-semibold">{t('stock.expiry_label')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.qty')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.free')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.rate')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.disc')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.gst')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('purchases.mrp')}</th>
                            <th className="px-3 py-2 text-right font-semibold">{t('bill.amount')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {p.items.map((item) => (
                            <tr key={item.line_no}>
                                <td className="px-3 py-2 text-ink-soft">{item.line_no}</td>
                                <td className="px-3 py-2 font-medium">{item.product_name}</td>
                                <td className="px-3 py-2">{item.batch_no ?? '—'}</td>
                                <td className="px-3 py-2">{item.expiry_date ? formatExpiry(item.expiry_date, i18n.language) : '—'}</td>
                                <td className="px-3 py-2 text-right">
                                    {item.qty} {unit(item.unit_code, item.qty)}
                                </td>
                                <td className="px-3 py-2 text-right">{item.free_qty > 0 ? item.free_qty : ''}</td>
                                <td className="px-3 py-2 text-right">{formatMoney(item.rate_paise, false)}</td>
                                <td className="px-3 py-2 text-right">{Number(item.discount_percent) > 0 ? `${Number(item.discount_percent)}%` : ''}</td>
                                <td className="px-3 py-2 text-right">{Number(item.tax_rate_percent)}%</td>
                                <td className="px-3 py-2 text-right">{item.mrp_paise > 0 ? formatMoney(item.mrp_paise, false) : ''}</td>
                                <td className="px-3 py-2 text-right font-semibold">{formatMoney(item.line_total_paise, false)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>

            <div className="grid gap-4 md:grid-cols-2">
                <Card title={t('purchases.totals')}>
                    <dl className="grid grid-cols-2 gap-y-1 text-sm">
                        <dt className="text-ink-soft">{t('purchases.subtotal')}</dt>
                        <dd className="text-right">{formatMoney(p.totals.gross_paise)}</dd>
                        {p.totals.discount_paise > 0 && (
                            <>
                                <dt className="text-ink-soft">{t('pos.discount')}</dt>
                                <dd className="text-right">− {formatMoney(p.totals.discount_paise)}</dd>
                            </>
                        )}
                        <dt className="text-ink-soft">{t('reports.col.taxable')}</dt>
                        <dd className="text-right">{formatMoney(p.totals.taxable_paise)}</dd>
                        <dt className="text-ink-soft">{p.inter_state ? 'IGST' : 'CGST + SGST'}</dt>
                        <dd className="text-right">{formatMoney(tax)}</dd>
                        {p.totals.other_charges_paise > 0 && (
                            <>
                                <dt className="text-ink-soft">{t('purchases.other_charges')}</dt>
                                <dd className="text-right">{formatMoney(p.totals.other_charges_paise)}</dd>
                            </>
                        )}
                        {p.totals.round_off_paise !== 0 && (
                            <>
                                <dt className="text-ink-soft">{t('bill.round_off')}</dt>
                                <dd className="text-right">{formatMoney(p.totals.round_off_paise)}</dd>
                            </>
                        )}
                        <dt className="border-t border-line pt-2 text-base font-semibold">{t('pos.total')}</dt>
                        <dd className="border-t border-line pt-2 text-right text-base font-bold">{formatMoney(p.total_paise)}</dd>
                    </dl>
                </Card>
                <Card title={t('purchases.payments')}>
                    {p.payments.length === 0 ? (
                        <p className="text-ink-soft">{t('purchases.no_payments')}</p>
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {p.payments.map((pay, i) => (
                                <li key={i} className="flex justify-between py-1.5">
                                    <span>
                                        {pay.direction === 'in' ? t('purchases.refund_back') : t(`pay.${pay.method}`)}
                                        {pay.reference_no && <span className="ml-1 text-ink-soft">({pay.reference_no})</span>}
                                        <span className="ml-2 text-ink-soft">{new Date(pay.at).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' })}</span>
                                    </span>
                                    <span className="font-semibold">{formatMoney(pay.amount_paise)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {p.status === 'confirmed' && (
                        <p className="mt-3 flex justify-between border-t border-line pt-2 font-semibold">
                            <span>{t('purchases.col_due')}</span>
                            <span className={due > 0 ? 'text-warning' : 'text-success-dark'}>{formatMoney(Math.max(due, 0))}</span>
                        </p>
                    )}
                </Card>
            </div>
            {p.notes && <p className="text-ink-soft">{p.notes}</p>}

            <Modal open={paying} onClose={() => setPaying(false)} title={t('suppliers.pay_to', { name: p.supplier.name })} closeLabel={t('common.close')}>
                {paying && <PayForm supplier={p.supplier} purchase={p} onDone={() => setPaying(false)} />}
            </Modal>
            <Modal open={cancelling} onClose={() => setCancelling(false)} title={t('purchases.cancel')} closeLabel={t('common.close')}>
                {cancelling && <CancelForm purchase={p} onDone={() => setCancelling(false)} />}
            </Modal>
        </div>
    );
}

function CancelForm({ purchase, onDone }: { purchase: Purchase; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (reason.trim().length < 3) return setMessage(t('validation.reason'));
        setBusy(true);
        setMessage(null);
        try {
            await api(`/purchases/${purchase.id}/cancel`, { method: 'POST', idempotencyKey: idempotencyKey.current, body: { reason: reason.trim() } });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.purchases });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.suppliers });
            void queryClient.invalidateQueries({ queryKey: ['products'] });
            toast('success', t('purchases.cancelled_done'));
            onDone();
        } catch (e) {
            setMessage(e instanceof ApiError && e.code ? t(`errors.${e.code}`, { defaultValue: t('app.error_generic') }) : t('app.error_generic'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <p className="text-ink-soft">{t('purchases.cancel_help')}</p>
            <TextField label={t('stock.reason')} value={reason} onChange={setReason} autoFocus maxLength={300} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="danger" busy={busy}>
                    {t('purchases.cancel')}
                </Button>
            </div>
        </form>
    );
}
