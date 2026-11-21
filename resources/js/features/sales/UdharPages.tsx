import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { ArrowLeft, HandCoins, Pencil, Printer, Search, UserPlus } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Alert, Button, Card, Modal, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { PAYMENT_METHODS, saleKeys, useCustomer, useCustomers, type Customer, type PaymentMethod } from './api';
import { CustomerForm } from './CustomerPicker';

/** Who owes the shop money, largest first. */
export function UdharPage() {
    const { t } = useTranslation();
    const { can } = useSession();
    const navigate = useNavigate();
    const [search, setSearch] = useState('');
    const [owingOnly, setOwingOnly] = useState(true);
    const [adding, setAdding] = useState(false);
    const customers = useCustomers(search, owingOnly && search === '');

    const chip = (active: boolean) =>
        clsx('min-h-8 rounded-full border px-3 text-sm font-medium', active ? 'border-primary bg-primary text-white' : 'border-line bg-white text-ink-soft');

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{t('nav.udhar')}</h1>
                {can('customers.manage') && (
                    <Button onClick={() => setAdding(true)} icon={<UserPlus className="size-5" aria-hidden />}>
                        {t('customers.add')}
                    </Button>
                )}
            </div>

            {customers.data && (
                <div className="rounded-lg bg-warning-soft px-5 py-4">
                    <p className="font-medium text-warning">{t('udhar.to_collect')}</p>
                    <p className="text-3xl font-bold text-ink">{formatMoney(customers.data.total_owed_paise)}</p>
                    <p className="text-ink-soft">{t('udhar.customers_owing', { count: customers.data.customers_owing })}</p>
                </div>
            )}

            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    autoFocus
                    aria-label={t('customers.search')}
                    placeholder={t('customers.search')}
                    className="block min-h-12 w-full rounded-lg border border-line bg-white pl-10 pr-4"
                />
            </div>
            <div className="flex gap-2">
                <button type="button" className={chip(owingOnly)} aria-pressed={owingOnly} onClick={() => setOwingOnly(true)}>
                    {t('udhar.owing')}
                </button>
                <button type="button" className={chip(!owingOnly)} aria-pressed={!owingOnly} onClick={() => setOwingOnly(false)}>
                    {t('udhar.all_customers')}
                </button>
            </div>

            {customers.isError && <Alert>{t('app.error_generic')}</Alert>}
            {customers.isPending && <Spinner label={t('common.loading')} />}
            {customers.data && customers.data.data.length === 0 && (
                <Card className="text-center text-lg font-semibold">{owingOnly && search === '' ? t('udhar.nobody_owes') : t('customers.none_found')}</Card>
            )}

            {customers.data && customers.data.data.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {customers.data.data.map((customer) => (
                        <li key={customer.id}>
                            <Link
                                to={`/customers/${customer.id}`}
                                className="flex min-h-16 flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-white px-3 py-2 hover:border-primary"
                            >
                                <span>
                                    <span className="block text-lg font-semibold">{customer.name}</span>
                                    <span className="block text-sm text-ink-soft">{[customer.mobile, customer.village].filter(Boolean).join(' · ')}</span>
                                </span>
                                <span className={clsx('text-xl font-bold', customer.balance_paise > 0 ? 'text-warning' : 'text-success-dark')}>
                                    {customer.balance_paise < 0 ? t('udhar.advance', { amount: formatMoney(-customer.balance_paise) }) : formatMoney(customer.balance_paise)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            <Modal open={adding} onClose={() => setAdding(false)} title={t('customers.add')} closeLabel={t('common.close')}>
                {adding && <CustomerForm showLimit={can('sales.price_override')} onCancel={() => setAdding(false)} onSaved={(c) => navigate(`/customers/${c.id}`)} />}
            </Modal>
        </div>
    );
}

/** One customer's udhar account: balance, statement, receive payment. */
export function CustomerPage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const id = Number(useParams().id);
    const customer = useCustomer(id);
    const queryClient = useQueryClient();
    const [receiving, setReceiving] = useState(false);
    const [editing, setEditing] = useState(false);

    if (customer.isError) return <Alert>{t('customers.not_found')}</Alert>;
    if (customer.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const c = customer.data;
    const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });

    return (
        <div className="flex flex-col gap-4">
            <Link to="/udhar" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline print:hidden">
                <ArrowLeft className="size-4" aria-hidden />
                {t('nav.udhar')}
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">{c.name}</h1>
                    <p className="text-ink-soft">{[c.mobile, c.village].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                        {t('common.print')}
                    </Button>
                    {can('customers.manage') && (
                        <Button variant="secondary" onClick={() => setEditing(true)} icon={<Pencil className="size-4" aria-hidden />}>
                            {t('common.edit')}
                        </Button>
                    )}
                    {can('credit.receive') && (
                        <Button variant="success" onClick={() => setReceiving(true)} icon={<HandCoins className="size-5" aria-hidden />}>
                            {t('udhar.receive')}
                        </Button>
                    )}
                </div>
            </div>

            <div className={clsx('rounded-lg px-5 py-4', c.balance_paise > 0 ? 'bg-warning-soft' : 'bg-success-soft')}>
                <p className="font-medium text-ink-soft">{c.balance_paise < 0 ? t('udhar.advance_label') : t('udhar.balance')}</p>
                <p className="text-3xl font-bold">{formatMoney(Math.abs(c.balance_paise))}</p>
                {c.credit_limit_paise > 0 && <p className="text-ink-soft">{t('udhar.limit', { amount: formatMoney(c.credit_limit_paise) })}</p>}
            </div>

            <Card title={t('udhar.statement')} className="overflow-x-auto">
                {c.entries.length === 0 ? (
                    <p className="text-ink-soft">{t('udhar.no_entries')}</p>
                ) : (
                    <table className="w-full min-w-[560px] text-left">
                        <thead className="border-b border-line text-sm text-ink-soft">
                            <tr>
                                <th className="py-2 pr-4 font-semibold">{t('sales.date')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('udhar.details')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">{t('udhar.bought')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">{t('udhar.paid')}</th>
                                <th className="py-2 text-right font-semibold">{t('udhar.balance')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {c.entries.map((entry) => (
                                <tr key={entry.id}>
                                    <td className="whitespace-nowrap py-2 pr-4">{day(entry.date)}</td>
                                    <td className="py-2 pr-4">
                                        {t(`udhar.entry.${entry.type}`, { defaultValue: entry.type })}
                                        {entry.sale_id ? (
                                            <Link to={`/sales/${entry.sale_id}`} className="ml-2 text-primary-dark underline print:no-underline">
                                                {entry.note}
                                            </Link>
                                        ) : (
                                            entry.note && <span className="ml-2 text-ink-soft">{entry.note}</span>
                                        )}
                                    </td>
                                    <td className="py-2 pr-4 text-right">{entry.debit_paise > 0 ? formatMoney(entry.debit_paise, false) : ''}</td>
                                    <td className="py-2 pr-4 text-right text-success-dark">{entry.credit_paise > 0 ? formatMoney(entry.credit_paise, false) : ''}</td>
                                    <td className="py-2 text-right font-semibold">{formatMoney(entry.balance_after_paise, false)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            <Modal open={receiving} onClose={() => setReceiving(false)} title={t('udhar.receive_from', { name: c.name })} closeLabel={t('common.close')}>
                {receiving && <ReceiveForm customer={c} onDone={() => setReceiving(false)} />}
            </Modal>
            <Modal open={editing} onClose={() => setEditing(false)} title={t('common.edit')} closeLabel={t('common.close')}>
                {editing && (
                    <CustomerForm
                        customer={c}
                        showLimit={can('sales.price_override')}
                        onCancel={() => setEditing(false)}
                        onSaved={() => {
                            void queryClient.invalidateQueries({ queryKey: saleKeys.customers });
                            setEditing(false);
                        }}
                    />
                )}
            </Modal>
        </div>
    );
}

function ReceiveForm({ customer, onDone }: { customer: Customer; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [amount, setAmount] = useState(customer.balance_paise > 0 ? (customer.balance_paise / 100).toFixed(2).replace(/\.00$/, '') : '');
    const [method, setMethod] = useState<PaymentMethod>('cash');
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    // After "no answer from the server" the payment may or may not have been saved. The same request can be
    // repeated safely; a changed one could not be told apart from a second payment — so the fields are frozen.
    const [unsure, setUnsure] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const paise = parseRupees(amount);
        if (paise === null || paise < 1) return setMessage(t('validation.amount'));

        setBusy(true);
        setMessage(null);
        try {
            await api(`/customers/${customer.id}/payments`, {
                method: 'POST',
                idempotencyKey: idempotencyKey.current,
                body: { amount_paise: paise, method, reference_no: reference.trim() || null },
            });
            await queryClient.invalidateQueries({ queryKey: saleKeys.customers });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast('success', t('udhar.received', { amount: formatMoney(paise) }));
            onDone();
        } catch (e) {
            if (e instanceof NetworkError) setUnsure(true);
            setMessage(
                e instanceof NetworkError
                    ? t('udhar.unsure')
                    : e instanceof ApiError && e.status === 403
                      ? t('app.forbidden')
                      : e instanceof ApiError && e.code
                        ? t(`errors.${e.code}`, { defaultValue: t('app.error_generic') })
                        : t('app.error_generic'),
            );
        } finally {
            setBusy(false);
        }
    }

    const paise = parseRupees(amount);
    const after = paise === null ? null : customer.balance_paise - paise;

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <p className="text-ink-soft">
                {t('udhar.balance')}: <strong className="text-ink">{formatMoney(customer.balance_paise)}</strong>
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('udhar.amount')} value={amount} onChange={setAmount} inputMode="decimal" autoFocus onFocus={(e) => e.target.select()} disabled={unsure} />
                <SelectField
                    label={t('sales.paid_by')}
                    value={method}
                    onChange={(value) => setMethod(value as PaymentMethod)}
                    disabled={unsure}
                    options={PAYMENT_METHODS.map((m) => ({ value: m, label: t(`pay.${m}`) }))}
                />
            </div>
            {method !== 'cash' && <TextField label={t('udhar.reference')} value={reference} onChange={setReference} optional={t('common.optional')} />}
            {after !== null && (
                <p className="rounded-md bg-surface px-3 py-2 font-semibold">
                    {after < 0 ? t('udhar.after_advance', { amount: formatMoney(-after) }) : t('udhar.after', { amount: formatMoney(after) })}
                </p>
            )}
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" size="lg" busy={busy}>
                    {busy ? t('common.saving') : t('udhar.receive')}
                </Button>
            </div>
        </form>
    );
}
