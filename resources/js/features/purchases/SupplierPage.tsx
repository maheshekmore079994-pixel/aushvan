import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { ArrowLeft, Banknote, Pencil, Phone, Plus, Printer, ShoppingBag, Trash2, Undo2 } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { Alert, Badge, Button, Card, Modal, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { PAYMENT_METHODS, type PaymentMethod } from '@/features/sales/api';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { CONTACT_ROLES, purchaseKeys, useSupplier, type ContactRole, type SupplierContact, type SupplierDetail } from './api';
import { DAY_KEYS, SupplierForm } from './SuppliersPage';

/** One supplier: what is owed, their people, the statement and their purchases. */
export function SupplierPage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const id = Number(useParams().id);
    const supplier = useSupplier(id);
    const queryClient = useQueryClient();
    const [paying, setPaying] = useState(false);
    const [editing, setEditing] = useState(false);
    const [contact, setContact] = useState<SupplierContact | 'new' | null>(null);

    if (supplier.isError) return <Alert>{t('suppliers.not_found')}</Alert>;
    if (supplier.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const s = supplier.data;
    const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });
    const refresh = () => queryClient.invalidateQueries({ queryKey: purchaseKeys.suppliers });

    async function removeContact(c: SupplierContact) {
        if (!window.confirm(t('suppliers.contact_remove_confirm', { name: c.name }))) return;
        await api(`/suppliers/${s.id}/contacts/${c.id}`, { method: 'DELETE' });
        await refresh();
    }

    return (
        <div className="flex flex-col gap-4">
            <Link to="/suppliers" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline print:hidden">
                <ArrowLeft className="size-4" aria-hidden />
                {t('nav.suppliers')}
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">{s.name}</h1>
                    <p className="text-ink-soft">{[t(`suppliers.kind.${s.kind}`), s.contact_person, s.mobile, s.gstin && `GSTIN ${s.gstin}`].filter(Boolean).join(' · ')}</p>
                    {s.drug_licence_no && <p className="text-sm text-ink-soft">{t('suppliers.drug_licence')}: {s.drug_licence_no}</p>}
                </div>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                        {t('common.print')}
                    </Button>
                    {can('suppliers.manage') && (
                        <Button variant="secondary" onClick={() => setEditing(true)} icon={<Pencil className="size-4" aria-hidden />}>
                            {t('common.edit')}
                        </Button>
                    )}
                    {can('purchases.manage') && (
                        <>
                            <Link to={`/purchases/new?supplier=${s.id}`} className="inline-flex">
                                <Button variant="secondary" icon={<ShoppingBag className="size-5" aria-hidden />}>
                                    {t('purchases.new')}
                                </Button>
                            </Link>
                            <Link to={`/purchase-returns/new?supplier=${s.id}`} className="inline-flex">
                                <Button variant="secondary" icon={<Undo2 className="size-5" aria-hidden />}>
                                    {t('purchases.return')}
                                </Button>
                            </Link>
                            <Button variant="success" onClick={() => setPaying(true)} icon={<Banknote className="size-5" aria-hidden />}>
                                {t('suppliers.pay')}
                            </Button>
                        </>
                    )}
                </div>
            </div>

            <div className={clsx('rounded-lg px-5 py-4', s.balance_paise > 0 ? 'bg-warning-soft' : 'bg-success-soft')}>
                <p className="font-medium text-ink-soft">{s.balance_paise < 0 ? t('suppliers.advance_label') : t('suppliers.balance')}</p>
                <p className="text-3xl font-bold">{formatMoney(Math.abs(s.balance_paise))}</p>
                {s.payment_terms_days > 0 && <p className="text-ink-soft">{t('suppliers.terms_line', { count: s.payment_terms_days })}</p>}
            </div>

            <Card title={t('suppliers.contacts')} className="print:hidden">
                {s.contacts.length === 0 && <p className="text-ink-soft">{t('suppliers.no_contacts')}</p>}
                <ul className="divide-y divide-line">
                    {s.contacts.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <div>
                                <p className="font-semibold">
                                    {c.name} <Badge tone="neutral">{t(`suppliers.role.${c.role}`)}</Badge>
                                    {c.company && <span className="ml-2 text-ink-soft">{c.company}</span>}
                                </p>
                                <p className="text-sm text-ink-soft">
                                    {c.visit_day !== null && <span className="mr-2">{t('suppliers.visits_on', { day: t(`suppliers.day.${DAY_KEYS[c.visit_day]}`) })}</span>}
                                    {c.schemes && <span>{t('suppliers.schemes')}: {c.schemes}</span>}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                {c.mobile && (
                                    <a href={`tel:${c.mobile}`} className="inline-flex min-h-9 items-center gap-1 rounded-md border border-line px-2 text-sm font-medium">
                                        <Phone className="size-4" aria-hidden /> {c.mobile}
                                    </a>
                                )}
                                {can('suppliers.manage') && (
                                    <>
                                        <Button variant="ghost" onClick={() => setContact(c)} aria-label={t('common.edit')} icon={<Pencil className="size-4" aria-hidden />} />
                                        <Button variant="ghost" onClick={() => void removeContact(c)} aria-label={t('suppliers.contact_remove')} icon={<Trash2 className="size-4" aria-hidden />} />
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
                {can('suppliers.manage') && (
                    <Button variant="secondary" className="mt-3" onClick={() => setContact('new')} icon={<Plus className="size-4" aria-hidden />}>
                        {t('suppliers.contact_add')}
                    </Button>
                )}
            </Card>

            <Card title={t('udhar.statement')} className="overflow-x-auto">
                {s.entries.length === 0 ? (
                    <p className="text-ink-soft">{t('udhar.no_entries')}</p>
                ) : (
                    <table className="w-full min-w-[560px] text-left">
                        <thead className="border-b border-line text-sm text-ink-soft">
                            <tr>
                                <th className="py-2 pr-4 font-semibold">{t('sales.date')}</th>
                                <th className="py-2 pr-4 font-semibold">{t('udhar.details')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">{t('suppliers.col_purchased')}</th>
                                <th className="py-2 pr-4 text-right font-semibold">{t('udhar.paid')}</th>
                                <th className="py-2 text-right font-semibold">{t('suppliers.col_balance')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {s.entries.map((entry) => (
                                <tr key={entry.id}>
                                    <td className="whitespace-nowrap py-2 pr-4">{day(entry.date)}</td>
                                    <td className="py-2 pr-4">
                                        {t(`suppliers.entry.${entry.type}`, { defaultValue: entry.type })}
                                        {entry.purchase_id ? (
                                            <Link to={`/purchases/${entry.purchase_id}`} className="ml-2 text-primary-dark underline print:no-underline">
                                                {entry.note}
                                            </Link>
                                        ) : entry.return_id ? (
                                            <Link to={`/purchase-returns/${entry.return_id}`} className="ml-2 text-primary-dark underline print:no-underline">
                                                {entry.note}
                                            </Link>
                                        ) : (
                                            entry.note && <span className="ml-2 text-ink-soft">{entry.note}</span>
                                        )}
                                    </td>
                                    <td className="py-2 pr-4 text-right">{entry.credit_paise > 0 ? formatMoney(entry.credit_paise, false) : ''}</td>
                                    <td className="py-2 pr-4 text-right text-success-dark">{entry.debit_paise > 0 ? formatMoney(entry.debit_paise, false) : ''}</td>
                                    <td className="py-2 text-right font-semibold">{formatMoney(entry.balance_after_paise, false)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            <Card title={t('purchases.recent')} className="print:hidden">
                {s.purchases.length === 0 ? (
                    <p className="text-ink-soft">{t('purchases.none_yet')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {s.purchases.map((p) => (
                            <li key={p.id}>
                                <Link to={`/purchases/${p.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2 hover:text-primary-dark">
                                    <span>
                                        <span className="font-semibold">{p.purchase_no ?? t('purchases.status.draft')}</span>
                                        <span className="ml-2 text-sm text-ink-soft">{[p.supplier_invoice_no, day(p.received_date)].filter(Boolean).join(' · ')}</span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <StatusBadge status={p.status} paid={p.paid_paise} total={p.total_paise} />
                                        <span className="font-semibold">{formatMoney(p.total_paise)}</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Modal open={paying} onClose={() => setPaying(false)} title={t('suppliers.pay_to', { name: s.name })} closeLabel={t('common.close')}>
                {paying && <PayForm supplier={s} onDone={() => setPaying(false)} />}
            </Modal>
            <Modal open={editing} onClose={() => setEditing(false)} title={t('common.edit')} closeLabel={t('common.close')}>
                {editing && (
                    <SupplierForm
                        supplier={s}
                        onCancel={() => setEditing(false)}
                        onSaved={() => {
                            void refresh();
                            setEditing(false);
                        }}
                    />
                )}
            </Modal>
            <Modal open={contact !== null} onClose={() => setContact(null)} title={contact === 'new' ? t('suppliers.contact_add') : t('common.edit')} closeLabel={t('common.close')}>
                {contact !== null && (
                    <ContactForm
                        supplierId={s.id}
                        contact={contact === 'new' ? undefined : contact}
                        onDone={() => {
                            void refresh();
                            setContact(null);
                        }}
                    />
                )}
            </Modal>
        </div>
    );
}

export function StatusBadge({ status, paid, total }: { status: string; paid: number; total: number }) {
    const { t } = useTranslation();
    if (status === 'draft') return <Badge tone="neutral">{t('purchases.status.draft')}</Badge>;
    if (status === 'cancelled') return <Badge tone="danger">{t('purchases.status.cancelled')}</Badge>;
    if (paid >= total) return <Badge tone="success">{t('purchases.status.paid')}</Badge>;
    if (paid > 0) return <Badge tone="warning">{t('purchases.status.part_paid')}</Badge>;
    return <Badge tone="warning">{t('purchases.status.unpaid')}</Badge>;
}

/** Money handed to a supplier, against one purchase or on account. */
export function PayForm({ supplier, purchase, onDone }: { supplier: { id: number; name: string; balance_paise: number }; purchase?: { id: number; purchase_no: string | null; total_paise: number; paid_paise: number }; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const idempotencyKey = useRef(newIdempotencyKey());
    const due = purchase ? purchase.total_paise - purchase.paid_paise : supplier.balance_paise;
    const [amount, setAmount] = useState(due > 0 ? (due / 100).toFixed(2).replace(/\.00$/, '') : '');
    const [method, setMethod] = useState<PaymentMethod>('cash');
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [unsure, setUnsure] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const paise = parseRupees(amount);
        if (paise === null || paise < 1) return setMessage(t('validation.amount'));
        setBusy(true);
        setMessage(null);
        try {
            await api(`/suppliers/${supplier.id}/payments`, {
                method: 'POST',
                idempotencyKey: idempotencyKey.current,
                body: { amount_paise: paise, method, reference_no: reference.trim() || null, purchase_id: purchase?.id ?? null },
            });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.suppliers });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.purchases });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast('success', t('suppliers.paid', { amount: formatMoney(paise) }));
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

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <p className="text-ink-soft">
                {purchase ? t('purchases.due_on', { no: purchase.purchase_no ?? '' }) : t('suppliers.balance')}: <strong className="text-ink">{formatMoney(Math.max(due, 0))}</strong>
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('udhar.amount')} value={amount} onChange={setAmount} inputMode="decimal" autoFocus onFocus={(e) => e.target.select()} disabled={unsure} />
                <SelectField label={t('sales.paid_by')} value={method} onChange={(v) => setMethod(v as PaymentMethod)} disabled={unsure} options={PAYMENT_METHODS.map((m) => ({ value: m, label: t(`pay.${m}`) }))} />
            </div>
            {method !== 'cash' && <TextField label={t('udhar.reference')} value={reference} onChange={setReference} optional={t('common.optional')} />}
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" size="lg" busy={busy}>
                    {busy ? t('common.saving') : t('suppliers.pay')}
                </Button>
            </div>
        </form>
    );
}

function ContactForm({ supplierId, contact, onDone }: { supplierId: number; contact?: SupplierContact; onDone: () => void }) {
    const { t } = useTranslation();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [form, setForm] = useState({
        name: contact?.name ?? '',
        role: (contact?.role ?? 'mr') as ContactRole,
        company: contact?.company ?? '',
        mobile: contact?.mobile ?? '',
        visit_day: contact?.visit_day === null || contact?.visit_day === undefined ? '' : String(contact.visit_day),
        schemes: contact?.schemes ?? '',
    });
    const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const field = (key: string) => (error instanceof ApiError ? error.first(key) : undefined);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        const body = {
            name: form.name.trim(),
            role: form.role,
            company: form.company.trim() || null,
            mobile: form.mobile.trim() || null,
            visit_day: form.visit_day === '' ? null : Number(form.visit_day),
            schemes: form.schemes.trim() || null,
        };
        try {
            if (contact) await api(`/suppliers/${supplierId}/contacts/${contact.id}`, { method: 'PUT', body });
            else await api(`/suppliers/${supplierId}/contacts`, { method: 'POST', body, idempotencyKey: idempotencyKey.current });
            onDone();
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {error !== null && !(error instanceof ApiError && error.status === 422) && <Alert>{t('app.error_generic')}</Alert>}
            <TextField label={t('customers.name')} value={form.name} onChange={set('name')} error={field('name')} autoFocus required />
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField label={t('suppliers.role_label')} value={form.role} onChange={(v) => set('role')(v)} options={CONTACT_ROLES.map((r) => ({ value: r, label: t(`suppliers.role.${r}`) }))} />
                <TextField label={t('suppliers.company')} value={form.company} onChange={set('company')} error={field('company')} help={t('suppliers.company_help')} optional={t('common.optional')} />
                <TextField label={t('customers.mobile')} value={form.mobile} onChange={(v) => set('mobile')(v.replace(/\D/g, '').slice(0, 10))} error={field('mobile')} inputMode="tel" optional={t('common.optional')} />
                <SelectField
                    label={t('suppliers.visit_day')}
                    value={form.visit_day}
                    onChange={(v) => set('visit_day')(v)}
                    options={[{ value: '', label: t('suppliers.no_fixed_day') }, ...DAY_KEYS.map((d, i) => ({ value: String(i), label: t(`suppliers.day.${d}`) }))]}
                />
            </div>
            <TextField label={t('suppliers.schemes')} value={form.schemes} onChange={set('schemes')} error={field('schemes')} help={t('suppliers.schemes_help')} optional={t('common.optional')} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy}>
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}

export type { SupplierDetail };
