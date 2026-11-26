import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import type { TFunction } from 'i18next';
import { Ban, Plus, Settings2 } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Card, CheckField, Modal, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { PAYMENT_METHODS, type PaymentMethod } from '@/features/sales/api';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { dayKeys, useExpenseCategories, useExpenses, type Expense, type ExpenseCategory } from './api';

const today = () => new Date().toISOString().slice(0, 10);
const monthStart = () => today().slice(0, 8) + '01';

function explain(e: unknown, t: TFunction): string {
    if (e instanceof NetworkError) return t('app.no_connection_help');
    if (e instanceof ApiError && e.status === 403) return t('app.forbidden');
    if (e instanceof ApiError && e.status === 422) return t('validation.check_fields');
    if (e instanceof ApiError && e.code) return t(`errors.${e.code}`, { defaultValue: t('app.error_generic') });
    return t('app.error_generic');
}

/** What the shop spent, by head, for a period; entering a new expense; cancelling a wrong one. */
export function ExpensesPage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const [from, setFrom] = useState(monthStart());
    const [to, setTo] = useState(today());
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [page, setPage] = useState(1);
    const [adding, setAdding] = useState(false);
    const [cancelling, setCancelling] = useState<Expense | null>(null);
    const [heads, setHeads] = useState(false);
    const expenses = useExpenses(from, to, categoryId, page);
    const categories = useExpenseCategories();
    const localName = (c: { name: string; name_local: string | null }) => (i18n.language !== 'en' && c.name_local ? c.name_local : c.name);
    const dateText = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{t('expenses.title')}</h1>
                <div className="flex gap-2">
                    {can('settings.manage') && (
                        <Button variant="secondary" onClick={() => setHeads(true)} icon={<Settings2 className="size-4" aria-hidden />}>
                            {t('expenses.heads')}
                        </Button>
                    )}
                    <Button onClick={() => setAdding(true)} icon={<Plus className="size-5" aria-hidden />}>
                        {t('expenses.add')}
                    </Button>
                </div>
            </div>

            <Card>
                <div className="grid gap-3 sm:grid-cols-3">
                    <TextField label={t('reports.from')} type="date" value={from} onChange={(v) => { setFrom(v); setPage(1); }} />
                    <TextField label={t('reports.to')} type="date" value={to} onChange={(v) => { setTo(v); setPage(1); }} />
                    <SelectField
                        label={t('expenses.head')}
                        value={categoryId === null ? '' : String(categoryId)}
                        onChange={(v) => { setCategoryId(v === '' ? null : Number(v)); setPage(1); }}
                        options={[{ value: '', label: t('expenses.all_heads') }, ...(categories.data ?? []).map((c) => ({ value: String(c.id), label: localName(c) }))]}
                    />
                </div>
            </Card>

            {expenses.isError && <Alert>{t('app.error_generic')}</Alert>}
            {expenses.isPending && <Spinner label={t('common.loading')} />}
            {expenses.data && (
                <div className="grid gap-4 lg:grid-cols-[1fr_300px]">
                    <Card className="overflow-x-auto p-0">
                        {expenses.data.data.length === 0 ? (
                            <p className="p-4 text-ink-soft">{t('expenses.none')}</p>
                        ) : (
                            <table className="w-full min-w-[600px] text-left text-sm">
                                <thead className="border-b border-line text-ink-soft">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">{t('sales.date')}</th>
                                        <th className="px-3 py-2 font-semibold">{t('expenses.head')}</th>
                                        <th className="px-3 py-2 font-semibold">{t('expenses.description')}</th>
                                        <th className="px-3 py-2 font-semibold">{t('sales.paid_by')}</th>
                                        <th className="px-3 py-2 text-right font-semibold">{t('bill.amount')}</th>
                                        <th className="w-10" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {expenses.data.data.map((e) => (
                                        <tr key={e.id} className={clsx(e.cancelled && 'text-ink-soft line-through')}>
                                            <td className="whitespace-nowrap px-3 py-2">{dateText(e.date)}</td>
                                            <td className="px-3 py-2 font-medium">{localName(e.category)}</td>
                                            <td className="px-3 py-2">
                                                {e.description}
                                                {e.cancelled && <span className="ml-2 no-underline">({t('expenses.cancelled_tag', { reason: e.cancelled.reason })})</span>}
                                            </td>
                                            <td className="px-3 py-2">{t(`pay.${e.method}`)}</td>
                                            <td className="px-3 py-2 text-right font-semibold">{formatMoney(e.amount_paise, false)}</td>
                                            <td className="px-1 py-1">
                                                {!e.cancelled && (
                                                    <Button variant="ghost" onClick={() => setCancelling(e)} aria-label={t('expenses.cancel')} icon={<Ban className="size-4" aria-hidden />} />
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {expenses.data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between gap-3 border-t border-line px-3 py-2 text-sm">
                                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                    {t('common.previous')}
                                </Button>
                                <span className="text-ink-soft">{t('common.page_of', { page, total: expenses.data.meta.last_page })}</span>
                                <Button variant="secondary" disabled={page >= expenses.data.meta.last_page} onClick={() => setPage(page + 1)}>
                                    {t('common.next')}
                                </Button>
                            </div>
                        )}
                    </Card>
                    <Card title={t('expenses.total_period')}>
                        <p className="text-3xl font-bold">{formatMoney(expenses.data.total_paise)}</p>
                        <ul className="mt-3 divide-y divide-line text-sm">
                            {expenses.data.by_category.map((c) => (
                                <li key={c.id} className="flex justify-between py-1.5">
                                    <span>{localName(c)}</span>
                                    <span className="font-semibold">{formatMoney(c.amount_paise, false)}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            )}

            <Modal open={adding} onClose={() => setAdding(false)} title={t('expenses.add')} closeLabel={t('common.close')} keepMounted>
                {adding && <ExpenseForm categories={categories.data ?? []} onDone={() => setAdding(false)} />}
            </Modal>
            <Modal open={cancelling !== null} onClose={() => setCancelling(null)} title={t('expenses.cancel')} closeLabel={t('common.close')}>
                {cancelling && <CancelForm expense={cancelling} onDone={() => setCancelling(null)} />}
            </Modal>
            <Modal open={heads} onClose={() => setHeads(false)} title={t('expenses.heads')} closeLabel={t('common.close')}>
                {heads && <HeadsEditor />}
            </Modal>
        </div>
    );
}

function ExpenseForm({ categories, onDone }: { categories: ExpenseCategory[]; onDone: () => void }) {
    const { t, i18n } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const key = useRef(newIdempotencyKey());
    const [form, setForm] = useState({ category: categories[0] ? String(categories[0].id) : '', amount: '', method: 'cash' as PaymentMethod, date: today(), description: '', reference: '' });
    const set = (k: keyof typeof form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const paise = parseRupees(form.amount);
        if (form.category === '') return setMessage(t('errors.expense_category_missing'));
        if (paise === null || paise < 1) return setMessage(t('validation.amount'));
        setBusy(true);
        setMessage(null);
        try {
            await api('/expenses', {
                method: 'POST',
                idempotencyKey: key.current,
                body: { expense_category_id: Number(form.category), amount_paise: paise, method: form.method, expense_date: form.date || null, description: form.description.trim() || null, reference_no: form.reference.trim() || null },
            });
            await queryClient.invalidateQueries({ queryKey: dayKeys.expenses });
            void queryClient.invalidateQueries({ queryKey: dayKeys.all });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast('success', t('expenses.saved'));
            onDone();
        } catch (e) {
            setMessage(explain(e, t));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField label={t('expenses.head')} value={form.category} onChange={set('category')} options={categories.map((c) => ({ value: String(c.id), label: i18n.language !== 'en' && c.name_local ? c.name_local : c.name }))} />
                <TextField label={t('udhar.amount')} value={form.amount} onChange={set('amount')} inputMode="decimal" autoFocus />
                <SelectField label={t('sales.paid_by')} value={form.method} onChange={(v) => set('method')(v)} options={PAYMENT_METHODS.map((m) => ({ value: m, label: t(`pay.${m}`) }))} />
                <TextField label={t('sales.date')} type="date" value={form.date} onChange={set('date')} />
            </div>
            <TextField label={t('expenses.description')} value={form.description} onChange={set('description')} optional={t('common.optional')} maxLength={300} />
            {form.method !== 'cash' && <TextField label={t('udhar.reference')} value={form.reference} onChange={set('reference')} optional={t('common.optional')} />}
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

function CancelForm({ expense, onDone }: { expense: Expense; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const key = useRef(newIdempotencyKey());
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (reason.trim().length < 3) return setMessage(t('validation.reason'));
        setBusy(true);
        try {
            await api(`/expenses/${expense.id}/cancel`, { method: 'POST', idempotencyKey: key.current, body: { reason: reason.trim() } });
            await queryClient.invalidateQueries({ queryKey: dayKeys.expenses });
            void queryClient.invalidateQueries({ queryKey: dayKeys.all });
            toast('success', t('expenses.cancelled_done'));
            onDone();
        } catch (e) {
            setMessage(explain(e, t));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <p className="text-ink-soft">{t('expenses.cancel_help', { amount: formatMoney(expense.amount_paise) })}</p>
            <TextField label={t('stock.reason')} value={reason} onChange={setReason} autoFocus maxLength={300} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="danger" busy={busy}>
                    {t('expenses.cancel')}
                </Button>
            </div>
        </form>
    );
}

/** The list of expense heads: add one, hide or show one. */
function HeadsEditor() {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const all = useExpenseCategories(true);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['expenses', 'categories'] });

    async function add(event: FormEvent) {
        event.preventDefault();
        if (name.trim() === '') return;
        setBusy(true);
        try {
            await api('/expense-categories', { method: 'POST', idempotencyKey: newIdempotencyKey(), body: { name: name.trim() } });
            setName('');
            await refresh();
        } catch (e) {
            setMessage(explain(e, t));
        } finally {
            setBusy(false);
        }
    }

    async function toggle(c: ExpenseCategory) {
        try {
            await api(`/expense-categories/${c.id}`, { method: 'PUT', body: { is_active: !c.is_active } });
            await refresh();
        } catch (e) {
            setMessage(explain(e, t));
        }
    }

    return (
        <div className="flex flex-col gap-4">
            {message && <Alert>{message}</Alert>}
            <form onSubmit={add} className="flex items-end gap-2" noValidate>
                <TextField label={t('expenses.new_head')} value={name} onChange={setName} className="flex-1" />
                <Button type="submit" busy={busy}>
                    {t('expenses.add_head')}
                </Button>
            </form>
            <ul className="divide-y divide-line">
                {(all.data ?? []).map((c) => (
                    <li key={c.id} className="py-1">
                        <CheckField label={c.name_local ? `${c.name} · ${c.name_local}` : c.name} checked={c.is_active} onChange={() => void toggle(c)} />
                    </li>
                ))}
            </ul>
        </div>
    );
}
