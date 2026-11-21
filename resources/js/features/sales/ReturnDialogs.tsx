import { useQueryClient } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Alert, Button, Modal, SelectField, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { productKeys, useUnitLabel } from '@/features/products/api';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { PAYMENT_METHODS, saleKeys, type Bill, type CreditNote, type PaymentMethod, type RefundMode, type ReturnBucket, type ReturnPreview } from './api';
import { QtyBox } from './PosPage';

type Line = { qty: number; bucket: ReturnBucket };

function errorText(t: TFunction, error: unknown): string | null {
    if (!error) return null;
    if (error instanceof NetworkError) return t('app.no_connection_help');
    if (error instanceof ApiError) return error.code ? t(`errors.${error.code}`, { defaultValue: error.message }) : t('app.error_generic');
    return t('app.error_generic');
}

/** Take goods back against a bill: which lines, how many, where they go, and how the money goes back. */
export function ReturnDialog({ open, bill, onClose }: { open: boolean; bill: Bill; onClose: () => void }) {
    const { t } = useTranslation();
    return (
        <Modal open={open} onClose={onClose} title={t('returns.title')} closeLabel={t('common.close')}>
            {open && <ReturnForm bill={bill} onClose={onClose} />}
        </Modal>
    );
}

function ReturnForm({ bill, onClose }: { bill: Bill; onClose: () => void }) {
    const { t } = useTranslation();
    const { can } = useSession();
    const unit = useUnitLabel();
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const canRestockMedicine = can('returns.restock_medicine');
    const returnable = bill.items.filter((item) => item.qty - item.returned_qty > 0);
    // A medicine line carries its schedule (even "NONE"); general goods carry none.
    const isMedicine = (item: Bill['items'][number]) => item.schedule_code !== null;
    const today = new Date().toISOString().slice(0, 10);
    const isExpired = (item: Bill['items'][number]) => item.expiry_date !== null && item.expiry_date < today;
    /** "Crocin 500 Tablet · Batch B1 · strip": tells two lines of one product apart. */
    const lineName = (item: Bill['items'][number]) => [item.product_name, item.batch_no && `${t('stock.batch_no')} ${item.batch_no}`, unit(item.unit_code, 1)].filter(Boolean).join(' · ');

    const [lines, setLines] = useState<Record<number, Line>>({});
    const [reason, setReason] = useState('');
    const [mode, setMode] = useState<RefundMode>(bill.customer && bill.customer.balance_paise > 0 ? 'account_credit' : 'refund');
    const [method, setMethod] = useState<PaymentMethod>(bill.payments[0]?.method ?? 'cash');
    const [reference, setReference] = useState('');
    const [preview, setPreview] = useState<{ answers: string; data: ReturnPreview } | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [local, setLocal] = useState<string | null>(null);
    // One key for this dialog: a retry after "no answer" cannot make a second return.
    const [key] = useState(newIdempotencyKey);

    const chosen = useMemo(() => Object.entries(lines).filter(([, l]) => l.qty > 0).map(([n, l]) => ({ line_no: Number(n), qty: l.qty, bucket: l.bucket })), [lines]);
    const asked = JSON.stringify(chosen);
    const priced = preview?.answers === asked ? preview.data : null; // never show, or confirm, a figure for other lines

    // The amount to give back comes from the server, from the prices on this bill.
    useEffect(() => {
        if (chosen.length === 0) {
            setPreview(null);
            return;
        }
        let current = true;
        const timer = setTimeout(() => {
            api<ReturnPreview>(`/sales/${bill.id}/returns/preview`, { method: 'POST', body: { lines: chosen } })
                .then((data) => {
                    if (!current) return;
                    setPreview({ answers: asked, data });
                    setError(null);
                })
                .catch((e: unknown) => {
                    if (!current) return;
                    setPreview(null);
                    setError(e);
                });
        }, 200);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [bill.id, chosen, asked]);

    const toggle = (item: Bill['items'][number], on: boolean) =>
        setLines((all) => {
            const next = { ...all };
            if (on) next[item.line_no] = { qty: item.qty - item.returned_qty, bucket: isMedicine(item) || isExpired(item) ? 'unsellable' : 'sellable' };
            else delete next[item.line_no];
            return next;
        });
    const setLine = (lineNo: number, change: Partial<Line>) => setLines((all) => ({ ...all, [lineNo]: { ...all[lineNo], ...change } }));

    const total = priced?.totals.total_paise ?? 0;

    async function submit(event: FormEvent) {
        event.preventDefault();
        setLocal(null);
        if (chosen.length === 0) return setLocal(t('returns.nothing_chosen'));
        if (reason.trim().length < 3) return setLocal(t('validation.reason'));
        setBusy(true);
        setError(null);
        try {
            const note = await api<CreditNote>(`/sales/${bill.id}/returns`, {
                method: 'POST',
                idempotencyKey: key,
                body: { lines: chosen, reason: reason.trim(), refund_mode: mode, refund_method: mode === 'refund' ? method : null, reference_no: mode === 'refund' && reference.trim() !== '' ? reference.trim() : null },
            });
            void queryClient.invalidateQueries({ queryKey: saleKeys.all });
            void queryClient.invalidateQueries({ queryKey: saleKeys.customers });
            void queryClient.invalidateQueries({ queryKey: productKeys.all });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            void queryClient.invalidateQueries({ queryKey: ['reports'] });
            toast('success', t('returns.done', { number: note.return_no }));
            onClose();
            navigate(`/returns/${note.id}`);
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const message = local ?? errorText(t, error);

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <p className="text-ink-soft">{t('returns.intro')}</p>
            {message && <Alert>{message}</Alert>}

            <ul className="flex flex-col gap-2">
                {returnable.map((item) => {
                    const line = lines[item.line_no];
                    const left = item.qty - item.returned_qty;
                    const medicine = isMedicine(item);
                    const canShelve = !isExpired(item) && (!medicine || canRestockMedicine);
                    return (
                        <li key={item.line_no} className={`rounded-lg border p-3 ${line ? 'border-primary bg-primary-soft/30' : 'border-line'}`}>
                            <label className="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" className="mt-1 size-5 accent-primary" checked={!!line} onChange={(e) => toggle(item, e.target.checked)} aria-label={lineName(item)} data-autofocus={item === returnable[0] ? true : undefined} />
                                <span className="min-w-0 flex-1">
                                    <span className="block font-semibold">{item.product_name}</span>
                                    <span className="block text-sm text-ink-soft">
                                        {item.qty} {unit(item.unit_code, item.qty)} · {formatMoney(item.line_total_paise)}
                                        {item.batch_no && ` · ${t('stock.batch_no')} ${item.batch_no}`}
                                        {item.returned_qty > 0 && ` · ${t('returns.already_returned', { qty: `${item.returned_qty} ${unit(item.unit_code, item.returned_qty)}` })}`}
                                    </span>
                                </span>
                            </label>
                            {line && (
                                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                    <label className="flex items-center gap-2">
                                        <span className="text-sm font-medium">{t('returns.qty_back')}</span>
                                        <QtyBox label={`${t('returns.qty_back')} ${lineName(item)}`} value={line.qty} max={left} onChange={(qty) => setLine(item.line_no, { qty })} className="min-h-10 w-20 rounded-md border border-line text-center text-lg font-bold" />
                                        <span className="text-sm text-ink-soft">{t('returns.of_sold', { qty: left, unit: unit(item.unit_code, left) })}</span>
                                    </label>
                                    <SelectField
                                        label={t('returns.bucket')}
                                        value={line.bucket}
                                        onChange={(v) => setLine(item.line_no, { bucket: v as ReturnBucket })}
                                        help={medicine && !canRestockMedicine ? t('returns.medicine_note') : undefined}
                                        options={[
                                            ...(canShelve ? [{ value: 'sellable', label: t('returns.bucket_sellable') }] : []),
                                            { value: 'unsellable', label: t('returns.bucket_unsellable') },
                                        ]}
                                    />
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            <TextField label={t('returns.reason')} value={reason} onChange={setReason} required maxLength={300} />

            <fieldset>
                <legend className="mb-1 text-sm font-medium">{t('returns.refund_mode_question')}</legend>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <label className={`flex flex-1 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 ${mode === 'refund' ? 'border-primary bg-primary-soft' : 'border-line'}`}>
                        <input type="radio" name="mode" className="size-4 accent-primary" checked={mode === 'refund'} onChange={() => setMode('refund')} />
                        {t('returns.refund')}
                    </label>
                    {bill.customer && bill.customer.balance_paise > 0 && (
                        <label className={`flex flex-1 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 ${mode === 'account_credit' ? 'border-primary bg-primary-soft' : 'border-line'}`}>
                            <input type="radio" name="mode" className="size-4 accent-primary" checked={mode === 'account_credit'} onChange={() => setMode('account_credit')} />
                            {t('returns.account_credit', { amount: formatMoney(Math.max(bill.customer.balance_paise, 0)) })}
                        </label>
                    )}
                </div>
            </fieldset>

            {mode === 'refund' && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField label={t('returns.method')} value={method} onChange={(v) => setMethod(v as PaymentMethod)} options={PAYMENT_METHODS.map((m) => ({ value: m, label: t(`pay.${m}`) }))} />
                    {method !== 'cash' && <TextField label={t('returns.reference')} value={reference} onChange={setReference} optional={t('common.optional')} maxLength={50} />}
                </div>
            )}

            <div className="flex items-center justify-between rounded-lg bg-rail px-4 py-3 text-white">
                <span className="text-sm uppercase tracking-wide text-white/65">{t('returns.to_give_back')}</span>
                <span className="text-2xl font-bold" aria-live="polite">
                    {formatMoney(total)}
                </span>
            </div>

            <div className="flex flex-wrap justify-end gap-2">
                <Button variant="secondary" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy} disabled={chosen.length === 0 || !priced}>
                    {mode === 'refund' ? t('returns.confirm', { amount: formatMoney(total) }) : t('returns.confirm_credit', { amount: formatMoney(total) })}
                </Button>
            </div>
        </form>
    );
}

/** Cancel a bill made by mistake today. */
export function CancelDialog({ open, bill, onClose }: { open: boolean; bill: Bill; onClose: () => void }) {
    const { t } = useTranslation();
    return (
        <Modal open={open} onClose={onClose} title={t('returns.cancel_title')} closeLabel={t('common.close')}>
            {open && <CancelForm bill={bill} onClose={onClose} />}
        </Modal>
    );
}

function CancelForm({ bill, onClose }: { bill: Bill; onClose: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [local, setLocal] = useState<string | null>(null);
    const [key] = useState(newIdempotencyKey);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setLocal(null);
        if (reason.trim().length < 3) return setLocal(t('validation.reason'));
        setBusy(true);
        setError(null);
        try {
            await api(`/sales/${bill.id}/cancel`, { method: 'POST', idempotencyKey: key, body: { reason: reason.trim() } });
            queryClient.setQueryData<Bill>(['sales', 'bill', bill.id], (b) => b && { ...b, status: 'cancelled', can_cancel: false, can_return: false });
            void queryClient.invalidateQueries({ queryKey: saleKeys.all });
            void queryClient.invalidateQueries({ queryKey: saleKeys.customers });
            void queryClient.invalidateQueries({ queryKey: productKeys.all });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            void queryClient.invalidateQueries({ queryKey: ['reports'] });
            toast('success', t('returns.cancelled', { number: bill.invoice_no }));
            onClose();
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const message = local ?? errorText(t, error);

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <Alert tone="warning">{t('returns.cancel_help')}</Alert>
            {message && <Alert>{message}</Alert>}
            <TextField label={t('returns.cancel_reason')} value={reason} onChange={setReason} required maxLength={300} autoFocus />
            <div className="flex flex-wrap justify-end gap-2">
                <Button variant="secondary" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="danger" busy={busy}>
                    {t('returns.cancel_confirm', { number: bill.invoice_no })}
                </Button>
            </div>
        </form>
    );
}
