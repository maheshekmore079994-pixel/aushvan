import { ArrowLeft, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { Alert, Button, Spinner } from '@/components/ui';
import { useUnitLabel } from '@/features/products/api';
import { formatMoney } from '@/lib/money';
import { useCreditNote } from './api';

/** A saved return, laid out like the bill so it prints on the same paper. */
export function CreditNotePage() {
    const { t, i18n } = useTranslation();
    const unit = useUnitLabel();
    const id = Number(useParams().id);
    const note = useCreditNote(id);

    if (note.isError) return <Alert>{t('bill.not_found')}</Alert>;
    if (note.isPending) return <Spinner label={t('common.loading')} shape="page" />;
    const n = note.data;
    const when = new Date(n.created_at).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' });
    const billDate = new Date(n.sale.sale_date).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });
    const row = 'flex justify-between gap-3';

    return (
        <div className="flex flex-col items-center gap-4">
            <div className="flex w-full max-w-md flex-wrap items-center justify-between gap-2 print:hidden">
                <Link to={`/sales/${n.sale.id}`} className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline">
                    <ArrowLeft className="size-4" aria-hidden />
                    {t('bill.number')} {n.sale.invoice_no}
                </Link>
                <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                    {t('returns.print')}
                </Button>
            </div>

            <article className="receipt w-full max-w-md rounded-lg border border-line bg-white p-5 text-sm leading-snug shadow-xs print:max-w-none print:rounded-none print:border-0 print:p-0 print:shadow-none">
                <header className="text-center">
                    <p className="text-lg font-bold uppercase">{t('returns.credit_note')}</p>
                    <p>{t('returns.against_bill', { number: n.sale.invoice_no, date: billDate })}</p>
                </header>
                <div className="my-2 border-y border-dashed border-line py-1">
                    <p className={row}>
                        <span>
                            {t('returns.credit_note')}: <strong>{n.return_no}</strong>
                        </span>
                        <span>{when}</span>
                    </p>
                    {n.customer && (
                        <p>
                            {t('bill.customer')}: {n.customer.name}
                        </p>
                    )}
                    <p>
                        {t('returns.reason_label')}: {n.reason}
                    </p>
                </div>
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-line text-left">
                            <th className="py-1 font-semibold">{t('bill.item')}</th>
                            <th className="py-1 pl-2 text-right font-semibold">{t('bill.qty')}</th>
                            <th className="py-1 pl-2 text-right font-semibold">{t('bill.amount')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {n.items.map((item) => (
                            <tr key={item.line_no} className="align-top">
                                <td className="py-1 pr-2">
                                    {item.product_name}
                                    <span className="block text-xs text-ink-soft">
                                        {[item.batch_no && `${t('stock.batch_no')} ${item.batch_no}`, t(`returns.bucket_${item.bucket}`)].filter(Boolean).join(' · ')}
                                    </span>
                                </td>
                                <td className="whitespace-nowrap py-1 pl-2 text-right">
                                    {item.qty} {unit(item.unit_code, item.qty)}
                                </td>
                                <td className="whitespace-nowrap py-1 pl-2 text-right">{formatMoney(item.line_total_paise, false)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="mt-2 border-t border-dashed border-line pt-1">
                    {n.totals.round_off_paise !== 0 && (
                        <p className={row}>
                            <span>{t('bill.round_off')}</span>
                            <span>{formatMoney(n.totals.round_off_paise, false)}</span>
                        </p>
                    )}
                    <p className={`${row} text-lg font-bold`}>
                        <span>{t('returns.to_give_back')}</span>
                        <span>{formatMoney(n.totals.total_paise)}</span>
                    </p>
                    <p className={row}>
                        <span>{t(`returns.refund_mode.${n.refund_mode}`)}</span>
                        <span>{n.refund_method ? t(`pay.${n.refund_method}`) : ''}</span>
                    </p>
                    <p className={row}>
                        <span>{n.refund_mode === 'account_credit' && n.customer ? t('returns.balance_now') : ''}</span>
                        <span>{n.refund_mode === 'account_credit' && n.customer ? formatMoney(n.customer.balance_paise) : ''}</span>
                    </p>
                </div>
                <footer className="mt-3 border-t border-dashed border-line pt-2 text-center text-xs">
                    <p>{t('returns.made_by', { name: n.by })}</p>
                </footer>
            </article>
        </div>
    );
}
