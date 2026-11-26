import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { ArrowDownToLine, ArrowUpFromLine, History, Lock, LockOpen, Printer, RotateCcw } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { Alert, Badge, Button, Card, Modal, PageHeader, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { dayKeys, MOVEMENT_TYPES, useDay, useDayHistory, useDaySession, type CashDay, type DayFigures, type MovementType } from './api';

function useErrorText() {
    const { t } = useTranslation();
    return (e: unknown) =>
        e instanceof NetworkError
            ? t('app.no_connection_help')
            : e instanceof ApiError && e.status === 403
              ? t('app.forbidden')
              : e instanceof ApiError && e.status === 422
                ? t('validation.check_fields')
                : e instanceof ApiError && e.code
                  ? t(`errors.${e.code}`, { defaultValue: t('app.error_generic') })
                  : t('app.error_generic');
}

/** The cash drawer today: open it with the float, watch what should be in it, count and close. */
export function DayPage() {
    const { t, i18n } = useTranslation();
    const { can } = useSession();
    const day = useDay();
    const [opening, setOpening] = useState(false);
    const [closing, setClosing] = useState(false);
    const [moving, setMoving] = useState<MovementType | null>(null);
    const [reopening, setReopening] = useState(false);

    if (day.isError) return <Alert>{t('app.error_generic')}</Alert>;
    if (day.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const { open, last_closed: last } = day.data;
    const dateText = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'full' });

    return (
        <div className="flex flex-col gap-4">
            <PageHeader title={t('day.title')} subtitle={open ? t('day.open_since', { date: dateText(open.business_date), by: open.opened_by ?? '' }) : t('day.not_open')}>
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Link to="/day/history" className="inline-flex">
                        <Button variant="secondary" icon={<History className="size-4" aria-hidden />}>
                            {t('day.history')}
                        </Button>
                    </Link>
                    {open && (
                        <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-4" aria-hidden />}>
                            {t('common.print')}
                        </Button>
                    )}
                    {!open && can('day.close') && (
                        <Button onClick={() => setOpening(true)} icon={<LockOpen className="size-5" aria-hidden />}>
                            {t('day.open')}
                        </Button>
                    )}
                    {open && can('day.close') && (
                        <>
                            <Button variant="secondary" onClick={() => setMoving('cash_in')} icon={<ArrowDownToLine className="size-4" aria-hidden />}>
                                {t('day.put_in')}
                            </Button>
                            <Button variant="secondary" onClick={() => setMoving('bank_deposit')} icon={<ArrowUpFromLine className="size-4" aria-hidden />}>
                                {t('day.take_out')}
                            </Button>
                            <Button variant="success" onClick={() => setClosing(true)} icon={<Lock className="size-5" aria-hidden />}>
                                {t('day.close')}
                            </Button>
                        </>
                    )}
                </div>
            </PageHeader>

            {!open && last && (
                <Card title={t('day.last_closed', { date: dateText(last.business_date) })}>
                    <ClosedSummary day={last} />
                    {can('day.reopen') && (
                        <Button variant="secondary" className="mt-3" onClick={() => setReopening(true)} icon={<RotateCcw className="size-4" aria-hidden />}>
                            {t('day.reopen')}
                        </Button>
                    )}
                </Card>
            )}
            {!open && !last && <Card className="text-ink-soft">{t('day.intro')}</Card>}

            {open?.figures && <Figures figures={open.figures} live />}

            <Modal open={opening} onClose={() => setOpening(false)} title={t('day.open')} closeLabel={t('common.close')}>
                {opening && <OpenForm suggested={day.data.suggested_opening_paise} onDone={() => setOpening(false)} />}
            </Modal>
            <Modal open={closing} onClose={() => setClosing(false)} title={t('day.close')} closeLabel={t('common.close')} keepMounted>
                {open?.figures && <CloseForm expected={open.figures.expected_cash_paise} onDone={() => setClosing(false)} />}
            </Modal>
            <Modal open={moving !== null} onClose={() => setMoving(null)} title={moving === 'cash_in' ? t('day.put_in') : t('day.take_out')} closeLabel={t('common.close')}>
                {moving !== null && open?.figures && <MoveForm initial={moving} expected={open.figures.expected_cash_paise} onDone={() => setMoving(null)} />}
            </Modal>
            <Modal open={reopening} onClose={() => setReopening(false)} title={t('day.reopen')} closeLabel={t('common.close')}>
                {reopening && last && <ReopenForm day={last} onDone={() => setReopening(false)} />}
            </Modal>
        </div>
    );
}

/** The arithmetic, laid out as the owner reads it: what came in, what went out, what should be there. */
export function Figures({ figures, live }: { figures: DayFigures; live?: boolean }) {
    const { t } = useTranslation();
    const line = (label: string, paise: number, sign: '+' | '−' | '=' | '' = '', strong = false) => (
        <div className={clsx('flex items-center justify-between py-1', strong && 'border-t border-line pt-2 text-base font-semibold')}>
            <span className={strong ? '' : 'text-ink-soft'}>
                {sign && <span className="mr-1 inline-block w-3 text-center">{sign}</span>}
                {label}
            </span>
            <span className="tabular-nums">{formatMoney(paise)}</span>
        </div>
    );
    const c = figures.cash;

    return (
        <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
            <Card title={t('day.cash_in_drawer')}>
                <div className="text-sm">
                    {line(t('day.opening_cash'), figures.opening_cash_paise)}
                    {line(t('day.cash_sales'), c.cash_sales, '+')}
                    {line(t('day.udhar_received'), c.udhar_received, '+')}
                    {c.supplier_refunds > 0 && line(t('day.supplier_refunds'), c.supplier_refunds, '+')}
                    {c.other_in > 0 && line(t('day.other_in'), c.other_in, '+')}
                    {c.cash_in_paise > 0 && line(t('day.put_in'), c.cash_in_paise, '+')}
                    {line(t('day.refunds'), c.refunds, '−')}
                    {line(t('day.expenses'), c.expenses, '−')}
                    {line(t('day.paid_to_suppliers'), c.paid_to_suppliers, '−')}
                    {c.other_out > 0 && line(t('day.other_out'), c.other_out, '−')}
                    {line(t('day.taken_out'), c.cash_out_paise, '−')}
                    {line(t('day.expected'), figures.expected_cash_paise, '=', true)}
                </div>
                {live && <p className="mt-3 text-xs text-ink-soft">{t('day.counted_since')}</p>}
            </Card>
            <div className="flex flex-col gap-4">
                <Card title={t('day.non_cash')}>
                    <div className="text-sm">
                        {line(t('pay.upi'), figures.non_cash.upi)}
                        {line(t('pay.card'), figures.non_cash.card)}
                        {line(t('pay.bank'), figures.non_cash.bank)}
                    </div>
                    <p className="mt-2 text-xs text-ink-soft">{t('day.non_cash_help')}</p>
                </Card>
                <Card title={t('day.bills_today')}>
                    <p className="text-2xl font-bold">{formatMoney(figures.bills.total_paise)}</p>
                    <p className="text-sm text-ink-soft">{t('day.bills_line', { count: figures.bills.count, udhar: formatMoney(figures.bills.udhar_paise) })}</p>
                </Card>
                {figures.movements.length > 0 && (
                    <Card title={t('day.movements')}>
                        <ul className="divide-y divide-line text-sm">
                            {figures.movements.map((m) => (
                                <li key={m.id} className="flex justify-between py-1.5">
                                    <span>
                                        {t(`day.movement.${m.type}`)}
                                        {m.note && <span className="ml-1 text-ink-soft">· {m.note}</span>}
                                    </span>
                                    <span className={clsx('font-semibold', m.type === 'cash_in' ? 'text-success-dark' : 'text-danger')}>
                                        {m.type === 'cash_in' ? '+' : '−'} {formatMoney(m.amount_paise, false)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>
        </div>
    );
}

function ClosedSummary({ day }: { day: CashDay }) {
    const { t } = useTranslation();
    const diff = day.difference_paise ?? 0;
    return (
        <div className="flex flex-wrap gap-6 text-sm">
            <div>
                <p className="text-ink-soft">{t('day.expected')}</p>
                <p className="text-lg font-semibold">{formatMoney(day.expected_cash_paise ?? 0)}</p>
            </div>
            <div>
                <p className="text-ink-soft">{t('day.counted')}</p>
                <p className="text-lg font-semibold">{formatMoney(day.counted_cash_paise ?? 0)}</p>
            </div>
            <div>
                <p className="text-ink-soft">{t('day.difference')}</p>
                <p className={clsx('text-lg font-semibold', diff === 0 ? 'text-success-dark' : Math.abs(diff) <= 10000 ? 'text-warning' : 'text-danger')}>
                    {diff > 0 ? '+' : ''}
                    {formatMoney(diff)}
                </p>
            </div>
            <div>
                <p className="text-ink-soft">{t('day.closed_by')}</p>
                <p className="text-lg font-semibold">{day.closed_by ?? '—'}</p>
            </div>
            {day.note && <p className="w-full text-ink-soft">{day.note}</p>}
            {day.reopened && <Badge tone="warning">{t('day.was_reopened', { reason: day.reopened.reason })}</Badge>}
        </div>
    );
}

function OpenForm({ suggested, onDone }: { suggested: number; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const errorText = useErrorText();
    const key = useRef(newIdempotencyKey());
    const [amount, setAmount] = useState(suggested > 0 ? (suggested / 100).toFixed(2).replace(/\.00$/, '') : '');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const paise = parseRupees(amount);
        if (paise === null || paise < 0) return setMessage(t('validation.amount'));
        setBusy(true);
        try {
            await api('/day/open', { method: 'POST', idempotencyKey: key.current, body: { opening_cash_paise: paise } });
            await queryClient.invalidateQueries({ queryKey: dayKeys.all });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast('success', t('day.opened'));
            onDone();
        } catch (e) {
            setMessage(errorText(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <TextField label={t('day.opening_cash')} value={amount} onChange={setAmount} inputMode="decimal" autoFocus help={t('day.opening_help')} onFocus={(e) => e.target.select()} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" size="lg" busy={busy}>
                    {t('day.open')}
                </Button>
            </div>
        </form>
    );
}

function CloseForm({ expected, onDone }: { expected: number; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const errorText = useErrorText();
    const key = useRef(newIdempotencyKey());
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const counted = parseRupees(amount);
    const diff = counted === null ? null : counted - expected;

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (counted === null || counted < 0) return setMessage(t('validation.amount'));
        setBusy(true);
        try {
            await api('/day/close', { method: 'POST', idempotencyKey: key.current, body: { counted_cash_paise: counted, note: note.trim() || null } });
            await queryClient.invalidateQueries({ queryKey: dayKeys.all });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast('success', t('day.closed_done'));
            onDone();
        } catch (e) {
            setMessage(errorText(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <p className="text-ink-soft">
                {t('day.expected')}: <strong className="text-ink">{formatMoney(expected)}</strong>
            </p>
            <TextField label={t('day.counted')} value={amount} onChange={setAmount} inputMode="decimal" autoFocus help={t('day.counted_help')} />
            {diff !== null && (
                <p className={clsx('rounded-md px-3 py-2 font-semibold', diff === 0 ? 'bg-success-soft text-success-dark' : Math.abs(diff) <= 10000 ? 'bg-warning-soft text-warning' : 'bg-danger-soft text-danger')}>
                    {diff === 0 ? t('day.diff_ok') : diff > 0 ? t('day.diff_over', { amount: formatMoney(diff) }) : t('day.diff_short', { amount: formatMoney(-diff) })}
                </p>
            )}
            <TextField label={t('day.note')} value={note} onChange={setNote} optional={t('common.optional')} maxLength={300} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" size="lg" busy={busy}>
                    {t('day.close')}
                </Button>
            </div>
        </form>
    );
}

function MoveForm({ initial, expected, onDone }: { initial: MovementType; expected: number; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const errorText = useErrorText();
    const key = useRef(newIdempotencyKey());
    const [type, setType] = useState<MovementType>(initial);
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const paise = parseRupees(amount);
        if (paise === null || paise < 1) return setMessage(t('validation.amount'));
        if (type !== 'cash_in' && paise > expected) return setMessage(t('errors.not_enough_cash'));
        setBusy(true);
        try {
            await api('/day/movements', { method: 'POST', idempotencyKey: key.current, body: { type, amount_paise: paise, note: note.trim() || null } });
            await queryClient.invalidateQueries({ queryKey: dayKeys.all });
            toast('success', t('day.moved'));
            onDone();
        } catch (e) {
            setMessage(errorText(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <SelectField label={t('day.movement_type')} value={type} onChange={(v) => setType(v as MovementType)} options={MOVEMENT_TYPES.map((m) => ({ value: m, label: t(`day.movement.${m}`) }))} />
            <TextField label={t('udhar.amount')} value={amount} onChange={setAmount} inputMode="decimal" autoFocus />
            <TextField label={t('day.note')} value={note} onChange={setNote} optional={t('common.optional')} maxLength={300} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" busy={busy}>
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}

function ReopenForm({ day, onDone }: { day: CashDay; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const errorText = useErrorText();
    const key = useRef(newIdempotencyKey());
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (reason.trim().length < 3) return setMessage(t('validation.reason'));
        setBusy(true);
        try {
            await api(`/day/${day.id}/reopen`, { method: 'POST', idempotencyKey: key.current, body: { reason: reason.trim() } });
            await queryClient.invalidateQueries({ queryKey: dayKeys.all });
            toast('success', t('day.reopened'));
            onDone();
        } catch (e) {
            setMessage(errorText(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <p className="text-ink-soft">{t('day.reopen_help')}</p>
            <TextField label={t('stock.reason')} value={reason} onChange={setReason} autoFocus maxLength={300} />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="danger" busy={busy}>
                    {t('day.reopen')}
                </Button>
            </div>
        </form>
    );
}

/** Closed days, newest first, each opening its frozen figures. */
export function DayHistoryPage() {
    const { t, i18n } = useTranslation();
    const [page, setPage] = useState(1);
    const history = useDayHistory(page);
    const dateText = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium' });

    return (
        <div className="flex flex-col gap-4">
            <Link to="/day" className="font-medium text-primary-dark hover:underline">
                ← {t('day.title')}
            </Link>
            <h1 className="text-xl font-semibold tracking-tight">{t('day.history')}</h1>
            {history.isPending && <Spinner label={t('common.loading')} />}
            {history.data && history.data.data.length === 0 && <Card className="text-ink-soft">{t('day.no_history')}</Card>}
            {history.data && history.data.data.length > 0 && (
                <Card className="overflow-x-auto p-0">
                    <table className="w-full min-w-[640px] text-left text-sm">
                        <thead className="border-b border-line text-ink-soft">
                            <tr>
                                <th className="px-3 py-2 font-semibold">{t('sales.date')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('day.opening_cash')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('day.expected')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('day.counted')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('day.difference')}</th>
                                <th className="px-3 py-2 font-semibold">{t('day.closed_by')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {history.data.data.map((d) => (
                                <tr key={d.id}>
                                    <td className="px-3 py-2">
                                        <Link to={`/day/history/${d.id}`} className="font-semibold text-primary-dark hover:underline">
                                            {dateText(d.business_date)}
                                        </Link>
                                        {d.status === 'open' && <Badge tone="success">{t('day.status_open')}</Badge>}
                                    </td>
                                    <td className="px-3 py-2 text-right">{formatMoney(d.opening_cash_paise, false)}</td>
                                    <td className="px-3 py-2 text-right">{d.expected_cash_paise === null ? '' : formatMoney(d.expected_cash_paise, false)}</td>
                                    <td className="px-3 py-2 text-right">{d.counted_cash_paise === null ? '' : formatMoney(d.counted_cash_paise, false)}</td>
                                    <td className={clsx('px-3 py-2 text-right font-semibold', (d.difference_paise ?? 0) === 0 ? 'text-success-dark' : 'text-danger')}>
                                        {d.difference_paise === null ? '' : formatMoney(d.difference_paise, false)}
                                    </td>
                                    <td className="px-3 py-2">{d.closed_by ?? ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {history.data.meta.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t border-line px-3 py-2 text-sm">
                            <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                {t('common.previous')}
                            </Button>
                            <span className="text-ink-soft">{t('common.page_of', { page, total: history.data.meta.last_page })}</span>
                            <Button variant="secondary" disabled={page >= history.data.meta.last_page} onClick={() => setPage(page + 1)}>
                                {t('common.next')}
                            </Button>
                        </div>
                    )}
                </Card>
            )}
        </div>
    );
}

/** One closed day as it was frozen. */
export function DaySessionPage() {
    const { t, i18n } = useTranslation();
    const id = Number(useParams().id);
    const session = useDaySession(id);
    if (session.isError) return <Alert>{t('app.error_generic')}</Alert>;
    if (session.isPending) return <Spinner label={t('common.loading')} shape="page" />;
    const d = session.data;

    return (
        <div className="flex flex-col gap-4">
            <Link to="/day/history" className="font-medium text-primary-dark hover:underline print:hidden">
                ← {t('day.history')}
            </Link>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('day.title')} · {new Date(`${d.business_date}T00:00:00`).toLocaleDateString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'full' })}
                </h1>
                <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-4" aria-hidden />} className="print:hidden">
                    {t('common.print')}
                </Button>
            </div>
            <Card>
                <ClosedSummary day={d} />
            </Card>
            {d.figures && <Figures figures={d.figures} />}
        </div>
    );
}
