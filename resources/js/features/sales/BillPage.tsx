import { ArrowLeft, Ban, Plus, Printer, Undo2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Alert, Badge, Button, Spinner } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { useUnitLabel } from '@/features/products/api';
import { formatMoney } from '@/lib/money';
import { formatExpiry } from '@/lib/qty';
import { useBill, type Bill } from './api';
import { CancelDialog, ReturnDialog } from './ReturnDialogs';

/** A saved bill, laid out for an 80 mm receipt printer (it also prints on A4). */
export function BillPage() {
    const { t, i18n } = useTranslation();
    const navigate = useNavigate();
    const id = Number(useParams().id);
    const [params] = useSearchParams();
    const justMade = params.get('new') === '1';
    const bill = useBill(id);
    const newSale = useRef<HTMLButtonElement>(null);
    const { can } = useSession();
    const [returning, setReturning] = useState(false);
    const [cancelling, setCancelling] = useState(false);

    // Straight after a sale, Enter starts the next one.
    useEffect(() => {
        if (justMade && bill.data) newSale.current?.focus();
    }, [justMade, bill.data]);

    if (bill.isError) return <Alert>{t('bill.not_found')}</Alert>;
    if (bill.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    return (
        <div className="flex flex-col items-center gap-4">
            <div className="flex w-full max-w-md flex-wrap items-center justify-between gap-2 print:hidden">
                <Link to="/sales" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline">
                    <ArrowLeft className="size-4" aria-hidden />
                    {t('nav.sales')}
                </Link>
                <div className="flex flex-wrap gap-2">
                    {bill.data.can_return && can('sales.return') && (
                        <Button variant="secondary" onClick={() => setReturning(true)} icon={<Undo2 className="size-5" aria-hidden />}>
                            {t('returns.button')}
                        </Button>
                    )}
                    {bill.data.can_cancel && can('sales.cancel') && (
                        <Button variant="secondary" onClick={() => setCancelling(true)} icon={<Ban className="size-5 text-danger" aria-hidden />}>
                            {t('returns.cancel_button')}
                        </Button>
                    )}
                    <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                        {t('common.print')}
                    </Button>
                    <Button ref={newSale} variant="success" onClick={() => navigate('/sale')} icon={<Plus className="size-5" aria-hidden />}>
                        {t('nav.new_sale')}
                    </Button>
                </div>
            </div>
            {bill.data.cancelled && (
                <div className="w-full max-w-md">
                    <Alert>
                        {t('returns.cancelled_banner', {
                            when: bill.data.cancelled.at ? new Date(bill.data.cancelled.at).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' }) : '',
                            name: bill.data.cancelled.by ?? '',
                            reason: bill.data.cancelled.reason ?? '',
                        })}
                    </Alert>
                </div>
            )}
            <Receipt bill={bill.data} />
            {bill.data.returns.length > 0 && (
                <section className="w-full max-w-md rounded-lg border border-line bg-white p-4 print:hidden">
                    <h2 className="mb-2 font-semibold">{t('returns.list_title')}</h2>
                    <ul className="divide-y divide-line">
                        {bill.data.returns.map((r) => (
                            <li key={r.id} className="flex items-center justify-between gap-3 py-2">
                                <Link to={`/returns/${r.id}`} className="font-semibold text-primary-dark hover:underline">
                                    {r.return_no}
                                </Link>
                                <span className="text-sm text-ink-soft">{new Date(r.return_date).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' })}</span>
                                <Badge tone={r.refund_mode === 'refund' ? 'warning' : 'info'}>{t(`returns.refund_mode.${r.refund_mode}`)}</Badge>
                                <span className="font-bold">{formatMoney(r.total_paise)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            <ReturnDialog open={returning} bill={bill.data} onClose={() => setReturning(false)} />
            <CancelDialog open={cancelling} bill={bill.data} onClose={() => setCancelling(false)} />
        </div>
    );
}

function Receipt({ bill }: { bill: Bill }) {
    const { t, i18n } = useTranslation();
    const unit = useUnitLabel();
    const { shop, totals } = bill;
    const when = new Date(bill.completed_at).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' });
    const place = [shop.address, shop.village, shop.taluka, shop.district, shop.pincode].filter(Boolean).join(', ');
    const row = 'flex justify-between gap-3';

    return (
        <article className="receipt w-full max-w-md rounded-lg border border-line bg-white p-5 text-sm leading-snug shadow-xs print:max-w-none print:rounded-none print:border-0 print:p-0 print:shadow-none">
            <header className="text-center">
                <h1 className="text-lg font-bold">{shop.name}</h1>
                {place && <p>{place}</p>}
                {shop.phone && <p>{t('bill.phone', { phone: shop.phone })}</p>}
                {shop.gstin && <p>GSTIN: {shop.gstin}</p>}
                {bill.has_medicines && shop.drug_licences.length > 0 && <p>{t('bill.drug_licence', { numbers: shop.drug_licences.join(', ') })}</p>}
                <p className="mt-2 font-bold uppercase">{t(`bill.kind.${bill.doc_kind}`)}</p>
                {bill.status === 'cancelled' && <p className="text-lg font-bold uppercase tracking-widest text-danger">{t('returns.status_cancelled')}</p>}
                {shop.gst_mode === 'composition' && <p className="text-xs">composition taxable person, not eligible to collect tax on supplies</p>}
            </header>

            <div className="my-2 border-y border-dashed border-slate-400 py-1">
                <p className={row}>
                    <span>
                        {t('bill.number')}: <strong>{bill.invoice_no}</strong>
                    </span>
                    <span>{when}</span>
                </p>
                {bill.customer && (
                    <p>
                        {t('bill.customer')}: {bill.customer.name}
                        {bill.customer.village ? `, ${bill.customer.village}` : ''}
                    </p>
                )}
                {bill.prescription && (
                    <p>
                        {t('bill.patient')}: {bill.prescription.patient_name} · {t('bill.doctor')}: {bill.prescription.prescriber_name}
                    </p>
                )}
            </div>

            <table className="w-full">
                <thead>
                    <tr className="border-b border-slate-400 text-left">
                        <th className="py-1 font-semibold">{t('bill.item')}</th>
                        <th className="py-1 pl-2 text-right font-semibold">{t('bill.qty')}</th>
                        <th className="py-1 pl-2 text-right font-semibold">{t('bill.rate')}</th>
                        <th className="py-1 pl-2 text-right font-semibold">{t('bill.amount')}</th>
                    </tr>
                </thead>
                <tbody>
                    {bill.items.map((item) => (
                        <tr key={item.line_no} className="align-top">
                            <td className="py-1 pr-2">
                                {item.product_name}
                                {item.returned_qty > 0 && (
                                    <span className="block text-xs font-semibold text-danger sm:ml-1 sm:inline">{t('returns.returned_line', { qty: `${item.returned_qty} ${unit(item.unit_code, item.returned_qty)}` })}</span>
                                )}
                                {(item.batch_no || item.hsn_code) && (
                                    <span className="block text-xs text-slate-600">
                                        {[
                                            item.batch_no && `${t('stock.batch_no')} ${item.batch_no}`,
                                            item.expiry_date && `${t('stock.expiry_label')} ${formatExpiry(item.expiry_date, i18n.language)}`,
                                            item.hsn_code && shop.gst_mode === 'regular' && `HSN ${item.hsn_code}`,
                                            Number(item.tax_rate_percent) > 0 && `GST ${Number(item.tax_rate_percent)}%`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                )}
                            </td>
                            <td className="whitespace-nowrap py-1 text-right">
                                {item.qty} {unit(item.unit_code)}
                            </td>
                            <td className="whitespace-nowrap py-1 text-right">{formatMoney(Math.round((item.pack_price_paise * item.unit_factor) / item.pack_factor), false)}</td>
                            <td className="whitespace-nowrap py-1 text-right">{formatMoney(item.gross_paise, false)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <div className="mt-2 border-t border-dashed border-slate-400 pt-1">
                {totals.discount_paise > 0 && (
                    <p className={row}>
                        <span>{t('pos.discount')}</span>
                        <span>− {formatMoney(totals.discount_paise, false)}</span>
                    </p>
                )}
                {totals.round_off_paise !== 0 && (
                    <p className={row}>
                        <span>{t('bill.round_off')}</span>
                        <span>{formatMoney(totals.round_off_paise, false)}</span>
                    </p>
                )}
                <p className={`${row} text-lg font-bold`}>
                    <span>{t('pos.total')}</span>
                    <span>{formatMoney(totals.total_paise)}</span>
                </p>
                {bill.payments.map((payment, index) => (
                    <p key={index} className={row}>
                        <span>
                            {t(`pay.${payment.method}`)}
                            {payment.reference_no ? ` (${payment.reference_no})` : ''}
                        </span>
                        <span>{formatMoney(payment.amount_paise, false)}</span>
                    </p>
                ))}
                {bill.refunds.map((refund, index) => (
                    <p key={`r${index}`} className={`${row} text-danger`}>
                        <span>{t('returns.refunded_by', { method: t(`pay.${refund.method}`) })}</span>
                        <span>− {formatMoney(refund.amount_paise, false)}</span>
                    </p>
                ))}
                {totals.credit_paise > 0 && (
                    <p className={`${row} font-bold`}>
                        <span>{t('bill.udhar')}</span>
                        <span>{formatMoney(totals.credit_paise, false)}</span>
                    </p>
                )}
            </div>

            {bill.tax_summary.length > 0 && (
                <table className="mt-2 w-full border-t border-dashed border-slate-400 text-xs">
                    <thead>
                        <tr className="text-right">
                            <th className="py-1 text-left font-semibold">GST</th>
                            <th className="py-1 font-semibold">{t('bill.taxable')}</th>
                            <th className="py-1 font-semibold">CGST</th>
                            <th className="py-1 font-semibold">SGST</th>
                        </tr>
                    </thead>
                    <tbody>
                        {bill.tax_summary.map((tax) => (
                            <tr key={tax.rate_percent} className="text-right">
                                <td className="text-left">{Number(tax.rate_percent)}%</td>
                                <td>{formatMoney(tax.taxable_paise, false)}</td>
                                <td>{formatMoney(tax.cgst_paise, false)}</td>
                                <td>{formatMoney(tax.sgst_paise, false)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            <footer className="mt-3 border-t border-dashed border-slate-400 pt-2 text-center text-xs">
                {bill.pharmacist && (
                    <p>
                        {t('bill.pharmacist')}: {bill.pharmacist.name}
                        {bill.pharmacist.reg_no ? ` (${bill.pharmacist.reg_no})` : ''}
                    </p>
                )}
                <p>{t('bill.billed_by', { name: bill.cashier })}</p>
                {shop.footer && <p className="mt-1 text-sm">{shop.footer}</p>}
            </footer>
        </article>
    );
}
