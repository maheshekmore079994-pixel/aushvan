import { useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Modal, SelectField, TextField } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { api, ApiError, NetworkError } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { cartPayload, PAYMENT_METHODS, usePharmacists, type Bill, type CartCustomer, type CartLine, type PaymentMethod, type Preview } from './api';

type Props = {
    open: boolean;
    /** Hidden for a moment (the customer picker is in front) without losing what was typed. */
    suspended?: boolean;
    cartId: string;
    lines: CartLine[];
    preview: Preview;
    customer: CartCustomer | null;
    onChooseCustomer: () => void;
    onClose: () => void;
    /** differs = the server already had a bill for this cart id and it is not the cart on screen. */
    onDone: (bill: Bill, differs: boolean) => void;
};

export function PayDialog(props: Props) {
    const { t } = useTranslation();
    return (
        <Modal open={props.open && !props.suspended} keepMounted={props.open} onClose={props.onClose} title={`${t('pos.pay')} ${formatMoney(props.preview.totals.total_paise)}`} closeLabel={t('common.close')}>
            {props.open && <PayForm {...props} />}
        </Modal>
    );
}

const rupees = (paise: number) => (paise / 100).toFixed(2).replace(/\.00$/, '');

function PayForm({ cartId, lines, preview, customer, onChooseCustomer, onClose, onDone }: Props) {
    const { t } = useTranslation();
    const { user, can } = useSession();
    const queryClient = useQueryClient();
    const total = preview.totals.total_paise;
    const needsPin = preview.requires.pharmacist && !user.is_pharmacist;
    const pharmacists = usePharmacists(needsPin);

    // Amount applied to the bill per method. The ordinary sale is all cash, so that is the starting point.
    const [amounts, setAmounts] = useState<Record<PaymentMethod, string>>({ cash: rupees(total), upi: '', card: '', bank: '' });
    const [given, setGiven] = useState('');
    const [rx, setRx] = useState({ prescriber_name: '', prescriber_address: '', patient_name: customer?.name ?? '', patient_address: '' });
    const [pharmacistId, setPharmacistId] = useState('');
    const [pin, setPin] = useState('');
    const [overLimit, setOverLimit] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [local, setLocal] = useState<string | null>(null);

    const parsed = PAYMENT_METHODS.map((method) => ({ method, paise: amounts[method].trim() === '' ? 0 : parseRupees(amounts[method]) }));
    const invalid = parsed.some((p) => p.paise === null);
    const paid = parsed.reduce((sum, p) => sum + (p.paise ?? 0), 0);
    const udhar = total - paid;
    const givenPaise = given.trim() === '' ? null : parseRupees(given);
    const cashPaise = parsed[0].paise ?? 0;

    const fill = (method: PaymentMethod | 'udhar') =>
        setAmounts({ cash: '', upi: '', card: '', bank: '', ...(method === 'udhar' ? {} : { [method]: rupees(total) }) });

    async function submit(event: FormEvent) {
        event.preventDefault();
        setLocal(null);
        if (invalid) return setLocal(t('validation.amount'));
        if (udhar < 0) return setLocal(t('pos.error_overpaid'));
        if (udhar > 0 && !customer) return setLocal(t('errors.customer_required'));
        if (preview.requires.prescription && (rx.prescriber_name.trim() === '' || rx.patient_name.trim() === '')) return setLocal(t('errors.prescription_required'));
        if (needsPin && (pharmacistId === '' || pin === '')) return setLocal(t('errors.pharmacist_required'));

        setBusy(true);
        setError(null);
        try {
            const bill = await api<Bill>('/sales', {
                method: 'POST',
                body: {
                    ulid: cartId,
                    lines: cartPayload(lines),
                    customer_id: customer?.id ?? null,
                    payments: parsed.filter((p) => (p.paise ?? 0) > 0).map((p) => ({ method: p.method, amount_paise: p.paise })),
                    prescription: preview.requires.prescription ? rx : null,
                    pharmacist: needsPin ? { user_id: Number(pharmacistId), pin } : null,
                    override_credit_limit: overLimit,
                },
            });
            // If an earlier try was saved but its answer was lost, the server returns that earlier bill.
            // When the cart was changed in between, the bill does not match the screen: say so, loudly.
            onDone(bill, bill.totals.total_paise !== total || bill.totals.paid_paise !== paid);
        } catch (e) {
            setError(e);
            // A refusal (stock just sold at the other counter, batch expired…) must show on the cart lines too.
            if (e instanceof ApiError && e.status === 409) void queryClient.invalidateQueries({ queryKey: ['pos', 'preview'] });
            if (e instanceof ApiError && e.code === 'credit_limit_exceeded' && can('sales.price_override')) setOverLimit(true);
        } finally {
            setBusy(false);
        }
    }

    let message = local;
    if (!message && error instanceof NetworkError) message = t('pos.error_network');
    else if (!message && error instanceof ApiError) message = error.code ? t(`errors.${error.code}`, { defaultValue: error.message }) : t('app.error_generic');
    else if (!message && error) message = t('app.error_generic');

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
            {message && <Alert>{message}</Alert>}
            {overLimit && <Alert tone="warning">{t('pos.over_limit_confirm')}</Alert>}

            {preview.requires.prescription && (
                <fieldset className="rounded-md border-2 border-danger/40 p-3">
                    <legend className="px-2 font-semibold text-danger-dark">{t('pos.rx_title')}</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField label={t('pos.rx_doctor')} value={rx.prescriber_name} onChange={(v) => setRx({ ...rx, prescriber_name: v })} autoFocus required />
                        <TextField label={t('pos.rx_patient')} value={rx.patient_name} onChange={(v) => setRx({ ...rx, patient_name: v })} required />
                        <TextField label={t('pos.rx_doctor_address')} value={rx.prescriber_address} onChange={(v) => setRx({ ...rx, prescriber_address: v })} optional={t('common.optional')} />
                        <TextField label={t('pos.rx_patient_address')} value={rx.patient_address} onChange={(v) => setRx({ ...rx, patient_address: v })} optional={t('common.optional')} />
                    </div>
                </fieldset>
            )}

            {needsPin && (
                <fieldset className="rounded-md border-2 border-medical/40 p-3">
                    <legend className="px-2 font-semibold text-medical">{t('pos.pharmacist_title')}</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <SelectField
                            label={t('pos.pharmacist')}
                            value={pharmacistId}
                            onChange={setPharmacistId}
                            options={[{ value: '', label: '—' }, ...(pharmacists.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))]}
                        />
                        <TextField label={t('users.pin')} type="password" value={pin} onChange={(v) => setPin(v.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" autoComplete="off" />
                    </div>
                </fieldset>
            )}

            <div className="flex flex-wrap gap-2">
                {PAYMENT_METHODS.map((method) => (
                    <Button key={method} variant="secondary" onClick={() => fill(method)}>
                        {t('pos.all_by', { method: t(`pay.${method}`) })}
                    </Button>
                ))}
                <Button variant="secondary" onClick={() => fill('udhar')}>
                    {t('pos.all_udhar')}
                </Button>
            </div>

            <div className="grid gap-3 sm:grid-cols-4">
                {PAYMENT_METHODS.map((method, index) => (
                    <TextField
                        key={method}
                        label={t(`pay.${method}`)}
                        value={amounts[method]}
                        onChange={(v) => setAmounts({ ...amounts, [method]: v })}
                        inputMode="decimal"
                        autoFocus={index === 0 && !preview.requires.prescription}
                        onFocus={(e) => e.target.select()}
                    />
                ))}
            </div>

            <div className={`rounded-md px-3 py-2 text-lg font-semibold ${udhar > 0 ? 'bg-warning-soft text-warning' : 'bg-success-soft text-success-dark'}`}>
                {udhar > 0 ? (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            {t('pos.udhar_amount', { amount: formatMoney(udhar) })}
                            {customer ? ` — ${customer.name}` : ''}
                        </span>
                        {!customer && (
                            <Button variant="secondary" onClick={onChooseCustomer}>
                                {t('customers.choose')}
                            </Button>
                        )}
                    </div>
                ) : (
                    t('pos.fully_paid')
                )}
            </div>

            {cashPaise > 0 && (
                <div className="grid items-end gap-3 sm:grid-cols-2">
                    <TextField label={t('pos.cash_given')} value={given} onChange={setGiven} inputMode="decimal" optional={t('common.optional')} />
                    {givenPaise !== null && givenPaise >= cashPaise && (
                        <p className="pb-2 text-xl font-bold">{t('pos.change', { amount: formatMoney(givenPaise - cashPaise) })}</p>
                    )}
                </div>
            )}

            <div className="sticky -bottom-4 -mx-4 -mb-4 flex justify-end gap-3 border-t border-line bg-white px-4 py-3 sm:-bottom-5 sm:-mx-5 sm:-mb-5 sm:px-5">
                <Button variant="secondary" onClick={onClose} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" size="lg" busy={busy}>
                    {busy ? t('common.saving') : t('pos.complete')}
                </Button>
            </div>
        </form>
    );
}
