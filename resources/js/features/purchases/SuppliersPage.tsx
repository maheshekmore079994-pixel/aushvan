import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { Truck, UserPlus } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, Button, Card, Modal, PageHeader, SearchBox, SelectField, Spinner, TextField } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { purchaseKeys, SUPPLIER_KINDS, useSuppliers, useVisits, type Supplier, type SupplierKind } from './api';

const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;

/** Who the shop buys from, and what it owes each of them. */
export function SuppliersPage() {
    const { t } = useTranslation();
    const { can } = useSession();
    const navigate = useNavigate();
    const [search, setSearch] = useState('');
    const [owingOnly, setOwingOnly] = useState(false);
    const [adding, setAdding] = useState(false);
    const suppliers = useSuppliers(search, owingOnly && search === '');
    const visits = useVisits();
    const today = new Date().getDay();
    const todayVisits = (visits.data ?? []).filter((c) => c.visit_day === today);

    const chip = (active: boolean) =>
        clsx('min-h-8 rounded-full border px-3 text-sm font-medium', active ? 'border-primary bg-primary text-white' : 'border-line bg-white text-ink-soft');

    return (
        <div className="flex flex-col gap-4">
            <PageHeader title={t('nav.suppliers')}>
                {can('suppliers.manage') && (
                    <Button onClick={() => setAdding(true)} icon={<UserPlus className="size-5" aria-hidden />}>
                        {t('suppliers.add')}
                    </Button>
                )}
            </PageHeader>

            {suppliers.data && (
                <div className="rounded-lg bg-warning-soft px-5 py-4">
                    <p className="font-medium text-warning">{t('suppliers.to_pay')}</p>
                    <p className="text-3xl font-bold text-ink">{formatMoney(suppliers.data.total_owed_paise)}</p>
                    <p className="text-ink-soft">{t('suppliers.suppliers_owed', { count: suppliers.data.suppliers_owed })}</p>
                </div>
            )}

            {todayVisits.length > 0 && (
                <Alert tone="info">
                    {t('suppliers.visiting_today')}{' '}
                    {todayVisits.map((c) => (
                        <Link key={c.id} to={`/suppliers/${c.supplier.id}`} className="mr-2 font-semibold underline">
                            {c.name} ({c.company ?? c.supplier.name})
                        </Link>
                    ))}
                </Alert>
            )}

            <SearchBox value={search} onChange={setSearch} label={t('suppliers.search')} autoFocus />
            <div className="flex gap-2">
                <button type="button" className={chip(!owingOnly)} aria-pressed={!owingOnly} onClick={() => setOwingOnly(false)}>
                    {t('suppliers.all')}
                </button>
                <button type="button" className={chip(owingOnly)} aria-pressed={owingOnly} onClick={() => setOwingOnly(true)}>
                    {t('suppliers.owed')}
                </button>
            </div>

            {suppliers.isError && <Alert>{t('app.error_generic')}</Alert>}
            {suppliers.isPending && <Spinner label={t('common.loading')} />}
            {suppliers.data && suppliers.data.data.length === 0 && (
                <Card className="text-center text-lg font-semibold">{search === '' && !owingOnly ? t('suppliers.none_yet') : t('suppliers.none_found')}</Card>
            )}
            {suppliers.data && suppliers.data.data.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {suppliers.data.data.map((supplier) => (
                        <li key={supplier.id}>
                            <Link
                                to={`/suppliers/${supplier.id}`}
                                className="flex min-h-16 flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-white px-3 py-2 hover:border-primary"
                            >
                                <span className="flex items-center gap-3">
                                    <Truck className="size-5 text-ink-soft" aria-hidden />
                                    <span>
                                        <span className="block text-lg font-semibold">{supplier.name}</span>
                                        <span className="block text-sm text-ink-soft">
                                            {[t(`suppliers.kind.${supplier.kind}`), supplier.contact_person, supplier.mobile].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                </span>
                                <span className={clsx('text-xl font-bold', supplier.balance_paise > 0 ? 'text-warning' : 'text-success-dark')}>
                                    {formatMoney(Math.abs(supplier.balance_paise))}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            <Modal open={adding} onClose={() => setAdding(false)} title={t('suppliers.add')} closeLabel={t('common.close')}>
                {adding && <SupplierForm onCancel={() => setAdding(false)} onSaved={(s) => navigate(`/suppliers/${s.id}`)} />}
            </Modal>
        </div>
    );
}

type FormProps = { supplier?: Supplier; onSaved: (supplier: Supplier) => void; onCancel: () => void };

/** Add or edit a supplier. An opening balance (what the shop already owes) can be given only when adding. */
export function SupplierForm({ supplier, onSaved, onCancel }: FormProps) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [form, setForm] = useState({
        name: supplier?.name ?? '',
        kind: (supplier?.kind ?? 'distributor') as SupplierKind,
        contact_person: supplier?.contact_person ?? '',
        mobile: supplier?.mobile ?? '',
        gstin: supplier?.gstin ?? '',
        drug_licence_no: supplier?.drug_licence_no ?? '',
        address: supplier?.address ?? '',
        payment_terms_days: supplier ? String(supplier.payment_terms_days) : '',
        opening: '',
    });
    const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);

    async function save(confirmDuplicate: boolean) {
        setBusy(true);
        setError(null);
        const body = {
            name: form.name.trim(),
            kind: form.kind,
            contact_person: form.contact_person.trim() || null,
            mobile: form.mobile.trim() || null,
            gstin: form.gstin.trim().toUpperCase() || null,
            drug_licence_no: form.drug_licence_no.trim() || null,
            address: form.address.trim() || null,
            payment_terms_days: /^\d+$/.test(form.payment_terms_days) ? Number(form.payment_terms_days) : 0,
            opening_balance_paise: supplier ? undefined : (parseRupees(form.opening) ?? 0),
            confirm_duplicate: confirmDuplicate,
        };
        try {
            const saved = supplier
                ? await api<Supplier>(`/suppliers/${supplier.id}`, { method: 'PUT', body })
                : await api<Supplier>('/suppliers', { method: 'POST', body, idempotencyKey: idempotencyKey.current });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.suppliers });
            onSaved(saved);
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void save(false);
    };
    const field = (key: string) => (error instanceof ApiError ? error.first(key) : undefined);
    const duplicate = error instanceof ApiError && error.code === 'possible_duplicate';
    const duplicates = duplicate ? ((error.payload.duplicates ?? []) as { id: number; name: string }[]) : [];

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {duplicate && (
                <Alert tone="warning">
                    <p>{t('suppliers.duplicate', { names: duplicates.map((d) => d.name).join(', ') })}</p>
                    <Button className="mt-2" variant="secondary" onClick={() => void save(true)} busy={busy}>
                        {t('common.save_anyway')}
                    </Button>
                </Alert>
            )}
            {error !== null && !duplicate && !(error instanceof ApiError && error.status === 422) && <Alert>{t('app.error_generic')}</Alert>}
            <TextField label={t('suppliers.name')} value={form.name} onChange={set('name')} error={field('name')} autoFocus required />
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField label={t('suppliers.kind_label')} value={form.kind} onChange={(v) => set('kind')(v)} options={SUPPLIER_KINDS.map((k) => ({ value: k, label: t(`suppliers.kind.${k}`) }))} />
                <TextField label={t('suppliers.contact_person')} value={form.contact_person} onChange={set('contact_person')} error={field('contact_person')} optional={t('common.optional')} />
                <TextField label={t('customers.mobile')} value={form.mobile} onChange={(v) => set('mobile')(v.replace(/\D/g, '').slice(0, 10))} error={field('mobile')} inputMode="tel" optional={t('common.optional')} />
                <TextField label={t('suppliers.gstin')} value={form.gstin} onChange={(v) => set('gstin')(v.toUpperCase().slice(0, 15))} error={field('gstin')} help={t('suppliers.gstin_help')} optional={t('common.optional')} />
                <TextField label={t('suppliers.drug_licence')} value={form.drug_licence_no} onChange={set('drug_licence_no')} error={field('drug_licence_no')} optional={t('common.optional')} />
                <TextField
                    label={t('suppliers.terms')}
                    value={form.payment_terms_days}
                    onChange={(v) => set('payment_terms_days')(v.replace(/\D/g, '').slice(0, 3))}
                    error={field('payment_terms_days')}
                    help={t('suppliers.terms_help')}
                    inputMode="numeric"
                    optional={t('common.optional')}
                />
            </div>
            <TextField label={t('suppliers.address')} value={form.address} onChange={set('address')} error={field('address')} optional={t('common.optional')} />
            {!supplier && (
                <TextField label={t('suppliers.opening')} value={form.opening} onChange={set('opening')} error={field('opening_balance_paise')} help={t('suppliers.opening_help')} inputMode="decimal" optional={t('common.optional')} />
            )}
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onCancel} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy}>
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}

export { DAY_KEYS };
