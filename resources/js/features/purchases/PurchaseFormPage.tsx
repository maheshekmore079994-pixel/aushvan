import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import { ArrowLeft, PackagePlus, Save, Search, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Alert, Button, Card, Modal, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useProduct, useProducts, useUnitLabel, type ProductRow } from '@/features/products/api';
import { PAYMENT_METHODS, type PaymentMethod } from '@/features/sales/api';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { formatMoney, parseRupees } from '@/lib/money';
import { linePayload, purchaseKeys, usePurchase, usePurchasePreview, useSuppliers, type DraftLine, type Purchase, type PurchasePreview } from './api';

const DRAFT_KEY = 'rmgsms.purchase_draft';

type Header = { supplier_id: number | null; supplier_invoice_no: string; invoice_date: string; received_date: string; due_date: string; other_charges: string; invoice_total: string; notes: string };

const today = () => new Date().toISOString().slice(0, 10);
const blankHeader = (supplierId: number | null): Header => ({ supplier_id: supplierId, supplier_invoice_no: '', invoice_date: '', received_date: today(), due_date: '', other_charges: '', invoice_total: '', notes: '' });
const rupees = (paise: number | null | undefined) => (paise === null || paise === undefined ? '' : (paise / 100).toFixed(2).replace(/\.00$/, ''));

function lineFromProduct(p: Pick<ProductRow, 'id' | 'name' | 'pack_size' | 'base_unit' | 'pricing_unit' | 'mrp_paise' | 'type'>): DraftLine {
    return {
        key: `${p.id}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
        product_id: p.id,
        product_name: p.name,
        tracks_batch: p.type === 'medical',
        tracks_expiry: p.type === 'medical',
        pack_size: p.pack_size,
        base_unit: p.base_unit,
        pricing_unit: p.pricing_unit,
        unit: 'pack',
        qty: '',
        free_qty: '',
        rate: '',
        discount: '',
        batch_no: '',
        expiry_month: '',
        mrp: rupees(p.mrp_paise || null),
    };
}

function linesFromPurchase(p: Purchase): DraftLine[] {
    return p.items.map((i) => ({
        key: `saved-${i.line_no}`,
        product_id: i.product_id,
        product_name: i.product_name,
        tracks_batch: i.batch_no !== null,
        tracks_expiry: i.expiry_date !== null,
        pack_size: i.unit_factor > 1 ? i.unit_factor : 1,
        base_unit: i.unit_code,
        pricing_unit: i.unit_code,
        unit: i.unit_factor > 1 ? 'pack' : 'base',
        qty: String(i.qty),
        free_qty: i.free_qty > 0 ? String(i.free_qty) : '',
        rate: rupees(i.rate_paise),
        discount: Number(i.discount_percent) > 0 ? String(Number(i.discount_percent)) : '',
        batch_no: i.batch_no ?? '',
        expiry_month: i.expiry_date ? i.expiry_date.slice(0, 7) : '',
        mrp: rupees(i.mrp_paise || null),
    }));
}

/** Enter a supplier invoice line by line, see the server's totals, save as a draft, then confirm into stock. */
export function PurchaseFormPage() {
    const { t } = useTranslation();
    const editingId = Number(useParams().id ?? 0);
    const existing = usePurchase(editingId, editingId > 0);

    if (editingId > 0 && existing.isPending) return <Spinner label={t('common.loading')} shape="page" />;
    if (editingId > 0 && (existing.isError || !existing.data)) return <Alert>{t('purchases.not_found')}</Alert>;
    if (editingId > 0 && existing.data && existing.data.status !== 'draft') return <Alert>{t('errors.purchase_not_draft')}</Alert>;

    return <Editor key={editingId} existing={editingId > 0 ? existing.data : undefined} />;
}

function Editor({ existing }: { existing?: Purchase }) {
    const { t } = useTranslation();
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const unit = useUnitLabel();
    const [params] = useSearchParams();
    const saveKey = useRef(newIdempotencyKey());
    const confirmKey = useRef(newIdempotencyKey());

    const restored = useMemo(() => {
        if (existing) return null;
        try {
            const raw = localStorage.getItem(DRAFT_KEY);
            return raw ? (JSON.parse(raw) as { header: Header; lines: DraftLine[] }) : null;
        } catch {
            return null;
        }
    }, [existing]);

    const [header, setHeader] = useState<Header>(() =>
        existing
            ? {
                  supplier_id: existing.supplier.id,
                  supplier_invoice_no: existing.supplier_invoice_no ?? '',
                  invoice_date: existing.invoice_date ?? '',
                  received_date: existing.received_date,
                  due_date: existing.due_date ?? '',
                  other_charges: rupees(existing.other_charges_paise || null),
                  invoice_total: rupees(existing.invoice_total_paise),
                  notes: existing.notes ?? '',
              }
            : (restored?.header ?? blankHeader(params.get('supplier') ? Number(params.get('supplier')) : null)),
    );
    const [lines, setLines] = useState<DraftLine[]>(() => (existing ? linesFromPurchase(existing) : (restored?.lines ?? [])));
    const [purchaseId, setPurchaseId] = useState<number | null>(existing?.id ?? null);
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState<'save' | 'confirm' | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [duplicateOf, setDuplicateOf] = useState<{ id: number; purchase_no: string | null } | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [payNow, setPayNow] = useState('');
    const [payMethod, setPayMethod] = useState<PaymentMethod>('cash');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    const suppliers = useSuppliers('', false);
    const results = useProducts({ search, type: '', stock: '', page: 1 }, search.trim().length >= 2);
    const otherChargesPaise = parseRupees(header.other_charges) ?? 0;
    const preview = usePurchasePreview(header.supplier_id, lines, otherChargesPaise);
    const priced: PurchasePreview | undefined = preview.data;

    // A product just added from the "Add product" button comes back as ?product=ID and becomes a line once.
    const addedId = Number(params.get('product') ?? 0);
    const added = useProduct(addedId);
    const consumed = useRef(false);
    useEffect(() => {
        if (addedId > 0 && added.data && !consumed.current && !lines.some((l) => l.product_id === addedId)) {
            consumed.current = true;
            setLines((current) => [...current, lineFromProduct(added.data)]);
        }
    }, [addedId, added.data, lines]);

    // A new purchase survives a reload or a trip to the product form.
    useEffect(() => {
        if (existing) return;
        try {
            if (lines.length === 0 && header.supplier_invoice_no === '') localStorage.removeItem(DRAFT_KEY);
            else localStorage.setItem(DRAFT_KEY, JSON.stringify({ header, lines }));
        } catch {
            /* storage unavailable: nothing to keep */
        }
    }, [header, lines, existing]);

    const setH = (key: keyof Header) => (value: string) => setHeader((h) => ({ ...h, [key]: value }));
    const update = (key: string, patch: Partial<DraftLine>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
    const addProduct = (p: ProductRow) => {
        setLines((ls) => [...ls, lineFromProduct(p)]);
        setSearch('');
    };
    const lineError = (index: number) => priced?.errors.find((e) => e.index === index)?.code;
    const pricedLine = (index: number) => priced?.lines.find((l) => l.index === index);

    const supplierOptions = [{ value: '', label: t('purchases.choose_supplier') }, ...(suppliers.data?.data ?? []).map((s) => ({ value: String(s.id), label: s.name }))];
    const supplier = suppliers.data?.data.find((s) => s.id === header.supplier_id);

    function body(confirmDuplicate: boolean) {
        return {
            supplier_id: header.supplier_id,
            supplier_invoice_no: header.supplier_invoice_no.trim() || null,
            invoice_date: header.invoice_date || null,
            received_date: header.received_date || null,
            due_date: header.due_date || null,
            other_charges_paise: otherChargesPaise,
            invoice_total_paise: parseRupees(header.invoice_total),
            notes: header.notes.trim() || null,
            confirm_duplicate: confirmDuplicate,
            lines: linePayload(lines),
        };
    }

    function explain(e: unknown): string {
        if (e instanceof NetworkError) return t('app.no_connection_help');
        if (e instanceof ApiError) {
            if (e.status === 403) return t('app.forbidden');
            if (e.status === 422) {
                const errors = (e.payload.errors ?? {}) as Record<string, string[]>;
                setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
                return t('validation.check_fields');
            }
            if (e.code) return t(`errors.${e.code}`, { defaultValue: e.message });
        }
        return t('app.error_generic');
    }

    /** Saves the draft (create or replace lines) and returns its id, or null when refused. */
    async function saveDraft(confirmDuplicate = false): Promise<number | null> {
        setMessage(null);
        setFieldErrors({});
        setDuplicateOf(null);
        if (header.supplier_id === null) {
            setMessage(t('purchases.choose_supplier'));
            return null;
        }
        if (lines.length === 0) {
            setMessage(t('errors.purchase_empty'));
            return null;
        }
        try {
            const saved = purchaseId
                ? await api<Purchase>(`/purchases/${purchaseId}`, { method: 'PUT', body: body(confirmDuplicate) })
                : await api<Purchase>('/purchases', { method: 'POST', body: body(confirmDuplicate), idempotencyKey: saveKey.current });
            setPurchaseId(saved.id);
            localStorage.removeItem(DRAFT_KEY);
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.purchases });
            return saved.id;
        } catch (e) {
            if (e instanceof ApiError && e.code === 'duplicate_supplier_invoice') {
                setDuplicateOf(e.payload.purchase as { id: number; purchase_no: string | null });
                return null;
            }
            setMessage(explain(e));
            return null;
        }
    }

    async function onSaveDraft(confirmDuplicate = false) {
        setBusy('save');
        const id = await saveDraft(confirmDuplicate);
        setBusy(null);
        if (id) {
            toast('success', t('purchases.draft_saved'));
            navigate(`/purchases/${id}`, { replace: true });
        }
    }

    async function onConfirm() {
        setBusy('confirm');
        const id = await saveDraft(duplicateOf !== null);
        if (!id) {
            setBusy(null);
            setConfirming(false);
            return;
        }
        const paid = parseRupees(payNow) ?? 0;
        try {
            const confirmed = await api<Purchase>(`/purchases/${id}/confirm`, {
                method: 'POST',
                idempotencyKey: confirmKey.current,
                body: paid > 0 ? { payment: { amount_paise: paid, method: payMethod } } : {},
            });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.purchases });
            await queryClient.invalidateQueries({ queryKey: purchaseKeys.suppliers });
            void queryClient.invalidateQueries({ queryKey: ['products'] });
            void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast('success', t('purchases.confirmed_done', { no: confirmed.purchase_no ?? '' }));
            navigate(`/purchases/${id}`, { replace: true });
        } catch (e) {
            setConfirming(false);
            setMessage(explain(e));
        } finally {
            setBusy(null);
        }
    }

    const total = priced?.totals.total_paise ?? 0;
    const paperTotal = parseRupees(header.invoice_total);
    const tax = priced ? priced.totals.cgst_paise + priced.totals.sgst_paise + priced.totals.igst_paise + priced.totals.cess_paise : 0;
    const cell = 'min-h-9 w-full rounded-md border border-line bg-white px-2 text-sm focus:border-primary focus:outline-none';

    return (
        <div className="flex flex-col gap-4 pb-28 lg:pb-4">
            <Link to="/purchases" className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline">
                <ArrowLeft className="size-4" aria-hidden />
                {t('nav.purchases')}
            </Link>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{existing ? t('purchases.draft_title') : t('purchases.new')}</h1>
                <span className="rounded-full bg-surface px-3 py-1 text-sm font-medium text-ink-soft">{t('purchases.status.draft')}</span>
            </div>
            {message && <Alert>{message}</Alert>}
            {duplicateOf && (
                <Alert tone="warning">
                    <p>{t('purchases.duplicate_invoice', { no: header.supplier_invoice_no })}</p>
                    <div className="mt-2 flex gap-2">
                        <Link to={`/purchases/${duplicateOf.id}`} className="font-semibold underline">
                            {t('purchases.open_existing', { no: duplicateOf.purchase_no ?? t('purchases.status.draft') })}
                        </Link>
                        <Button variant="secondary" onClick={() => void onSaveDraft(true)} busy={busy === 'save'}>
                            {t('common.save_anyway')}
                        </Button>
                    </div>
                </Alert>
            )}

            <Card>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="lg:col-span-2">
                        <SelectField
                            label={t('purchases.supplier')}
                            value={header.supplier_id === null ? '' : String(header.supplier_id)}
                            onChange={(v) => setHeader((h) => ({ ...h, supplier_id: v === '' ? null : Number(v) }))}
                            options={supplierOptions}
                            error={fieldErrors.supplier_id}
                        />
                        {!suppliers.isPending && (suppliers.data?.data.length ?? 0) === 0 && (
                            <p className="mt-1 text-sm text-ink-soft">
                                {t('purchases.no_suppliers')}{' '}
                                <Link to="/suppliers" className="font-medium text-primary-dark underline">
                                    {t('suppliers.add')}
                                </Link>
                            </p>
                        )}
                    </div>
                    <TextField label={t('purchases.invoice_no')} value={header.supplier_invoice_no} onChange={setH('supplier_invoice_no')} error={fieldErrors.supplier_invoice_no} />
                    <TextField label={t('purchases.invoice_date')} type="date" value={header.invoice_date} onChange={setH('invoice_date')} error={fieldErrors.invoice_date} optional={t('common.optional')} />
                    <TextField label={t('purchases.received_date')} type="date" value={header.received_date} onChange={setH('received_date')} error={fieldErrors.received_date} />
                </div>
            </Card>

            <Card title={t('purchases.lines')} className="overflow-visible">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && results.data?.data[0]) {
                                e.preventDefault();
                                addProduct(results.data.data[0]);
                            }
                        }}
                        aria-label={t('purchases.add_line')}
                        placeholder={t('purchases.add_line')}
                        className="block min-h-12 w-full rounded-lg border border-line bg-white pl-10 pr-4 text-base"
                        autoFocus={lines.length === 0}
                    />
                    {search.trim().length >= 2 && (
                        <ul className="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-lg border border-line bg-white shadow-lg" role="listbox">
                            {results.isPending && <li className="px-3 py-2 text-ink-soft">{t('common.loading')}</li>}
                            {results.data && results.data.data.length === 0 && (
                                <li className="flex items-center justify-between gap-2 px-3 py-2">
                                    <span className="text-ink-soft">{t('customers.none_found')}</span>
                                    <Link to={`/products/new?then=${encodeURIComponent('/purchases/new')}`} className="inline-flex">
                                        <Button variant="secondary" icon={<PackagePlus className="size-4" aria-hidden />}>
                                            {t('products.add')}
                                        </Button>
                                    </Link>
                                </li>
                            )}
                            {(results.data?.data ?? []).map((p) => (
                                <li key={p.id}>
                                    <button type="button" role="option" aria-selected={false} onClick={() => addProduct(p)} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-surface">
                                        <span>
                                            <span className="font-medium">{p.name}</span>
                                            {p.generic_name && <span className="ml-2 text-sm text-ink-soft">{p.generic_name}</span>}
                                        </span>
                                        <span className="text-sm text-ink-soft">
                                            {p.pack_size > 1 ? `${p.pack_size} ${unit(p.base_unit)}/${unit(p.pricing_unit, 1)}` : unit(p.base_unit)}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {lines.length === 0 ? (
                    <p className="mt-4 text-ink-soft">{t('purchases.lines_help')}</p>
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[980px] text-left text-sm">
                            <thead className="text-ink-soft">
                                <tr>
                                    <th className="px-1 py-1 font-semibold">{t('purchases.product')}</th>
                                    <th className="w-28 px-1 py-1 font-semibold">{t('stock.batch_no')}</th>
                                    <th className="w-36 px-1 py-1 font-semibold">{t('stock.expiry_label')}</th>
                                    <th className="w-20 px-1 py-1 font-semibold">{t('purchases.qty')}</th>
                                    <th className="w-16 px-1 py-1 font-semibold">{t('purchases.free')}</th>
                                    <th className="w-28 px-1 py-1 font-semibold">{t('stock.unit')}</th>
                                    <th className="w-24 px-1 py-1 font-semibold">{t('purchases.rate')}</th>
                                    <th className="w-16 px-1 py-1 font-semibold">{t('purchases.disc')}</th>
                                    <th className="w-24 px-1 py-1 font-semibold">{t('purchases.mrp')}</th>
                                    <th className="w-14 px-1 py-1 text-right font-semibold">{t('purchases.gst')}</th>
                                    <th className="w-24 px-1 py-1 text-right font-semibold">{t('bill.amount')}</th>
                                    <th className="w-10" />
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line, index) => {
                                    const err = lineError(index);
                                    const pl = pricedLine(index);
                                    return (
                                        <tr key={line.key} className={clsx('align-top', err && 'bg-danger-soft/40')}>
                                            <td className="px-1 py-1">
                                                <p className="font-medium leading-tight">{line.product_name}</p>
                                                {err && <p className="text-xs text-danger">{t(`errors.${err}`, { defaultValue: err })}</p>}
                                                {pl && !err && (
                                                    <p className="text-xs text-ink-soft">
                                                        {t('purchases.cost_line', { amount: formatMoney(pl.cost_per_pack_paise), unit: unit(pl.pricing_unit, 1) })}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('stock.batch_no')} value={line.batch_no} onChange={(e) => update(line.key, { batch_no: e.target.value.toUpperCase() })} className={cell} />
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('stock.expiry_label')} type="month" value={line.expiry_month} onChange={(e) => update(line.key, { expiry_month: e.target.value })} className={cell} />
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('purchases.qty')} inputMode="numeric" value={line.qty} onChange={(e) => update(line.key, { qty: e.target.value.replace(/\D/g, '') })} className={cell} />
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('purchases.free')} inputMode="numeric" value={line.free_qty} onChange={(e) => update(line.key, { free_qty: e.target.value.replace(/\D/g, '') })} className={cell} />
                                            </td>
                                            <td className="px-1 py-1">
                                                {line.pack_size > 1 ? (
                                                    <select aria-label={t('stock.unit')} value={line.unit} onChange={(e) => update(line.key, { unit: e.target.value as 'pack' | 'base' })} className={cell}>
                                                        <option value="pack">{unit(line.pricing_unit)}</option>
                                                        <option value="base">{unit(line.base_unit)}</option>
                                                    </select>
                                                ) : (
                                                    <span className="inline-block py-2">{unit(line.base_unit)}</span>
                                                )}
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('purchases.rate')} inputMode="decimal" value={line.rate} onChange={(e) => update(line.key, { rate: e.target.value })} className={cell} />
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('purchases.disc')} inputMode="decimal" value={line.discount} onChange={(e) => update(line.key, { discount: e.target.value })} className={cell} />
                                            </td>
                                            <td className="px-1 py-1">
                                                <input aria-label={t('purchases.mrp')} inputMode="decimal" value={line.mrp} onChange={(e) => update(line.key, { mrp: e.target.value })} className={cell} />
                                            </td>
                                            <td className="px-1 py-2 text-right">{pl ? `${Number(pl.tax_rate_percent)}%` : ''}</td>
                                            <td className="px-1 py-2 text-right font-semibold">{pl ? formatMoney(pl.line_total_paise, false) : ''}</td>
                                            <td className="px-1 py-1">
                                                <Button variant="ghost" onClick={() => setLines(lines.filter((l) => l.key !== line.key))} aria-label={t('pos.remove', { name: '' }).trim()} icon={<Trash2 className="size-4" aria-hidden />} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
                <Card>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <TextField label={t('purchases.other_charges')} value={header.other_charges} onChange={setH('other_charges')} inputMode="decimal" optional={t('common.optional')} help={t('purchases.other_charges_help')} />
                        <TextField label={t('purchases.due_date')} type="date" value={header.due_date} onChange={setH('due_date')} error={fieldErrors.due_date} optional={t('common.optional')} help={supplier && supplier.payment_terms_days > 0 ? t('suppliers.terms_line', { count: supplier.payment_terms_days }) : undefined} />
                        <TextField label={t('purchases.invoice_total')} value={header.invoice_total} onChange={setH('invoice_total')} inputMode="decimal" optional={t('common.optional')} help={t('purchases.invoice_total_help')} />
                    </div>
                    <TextField label={t('purchases.notes')} value={header.notes} onChange={setH('notes')} optional={t('common.optional')} className="mt-3" />
                </Card>
                <Card title={t('purchases.totals')}>
                    {priced ? (
                        <dl className="grid grid-cols-2 gap-y-1 text-sm">
                            <dt className="text-ink-soft">{t('reports.col.taxable')}</dt>
                            <dd className="text-right">{formatMoney(priced.totals.taxable_paise)}</dd>
                            <dt className="text-ink-soft">{priced.inter_state ? 'IGST' : 'GST'}</dt>
                            <dd className="text-right">{formatMoney(tax)}</dd>
                            {otherChargesPaise > 0 && (
                                <>
                                    <dt className="text-ink-soft">{t('purchases.other_charges')}</dt>
                                    <dd className="text-right">{formatMoney(otherChargesPaise)}</dd>
                                </>
                            )}
                            <dt className="text-ink-soft">{t('bill.round_off')}</dt>
                            <dd className="text-right">{formatMoney(priced.totals.round_off_paise)}</dd>
                            <dt className="border-t border-line pt-2 text-base font-semibold">{t('pos.total')}</dt>
                            <dd className="border-t border-line pt-2 text-right text-2xl font-bold">{formatMoney(total)}</dd>
                        </dl>
                    ) : (
                        <p className="text-ink-soft">{t('purchases.totals_help')}</p>
                    )}
                    {priced && paperTotal !== null && (
                        <p className={clsx('mt-2 rounded-md px-3 py-2 text-sm font-semibold', paperTotal === total ? 'bg-success-soft text-success-dark' : 'bg-warning-soft text-warning')}>
                            {paperTotal === total ? t('purchases.total_matches') : t('purchases.total_differs', { paper: formatMoney(paperTotal), ours: formatMoney(total) })}
                        </p>
                    )}
                    <div className="mt-4 flex flex-col gap-2">
                        <Button variant="secondary" onClick={() => void onSaveDraft()} busy={busy === 'save'} disabled={busy !== null} icon={<Save className="size-4" aria-hidden />}>
                            {t('purchases.save_draft')}
                        </Button>
                        <Button variant="success" size="lg" onClick={() => setConfirming(true)} disabled={busy !== null || lines.length === 0 || (priced?.errors.length ?? 0) > 0}>
                            {t('purchases.confirm')}
                        </Button>
                    </div>
                </Card>
            </div>

            <Modal open={confirming} onClose={() => setConfirming(false)} title={t('purchases.confirm')} closeLabel={t('common.close')} keepMounted>
                <div className="flex flex-col gap-4">
                    <p>{t('purchases.confirm_help', { total: formatMoney(total), supplier: supplier?.name ?? '' })}</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField label={t('purchases.paid_now')} value={payNow} onChange={setPayNow} inputMode="decimal" help={t('purchases.paid_now_help')} />
                        <SelectField label={t('sales.paid_by')} value={payMethod} onChange={(v) => setPayMethod(v as PaymentMethod)} options={PAYMENT_METHODS.map((m) => ({ value: m, label: t(`pay.${m}`) }))} />
                    </div>
                    {(parseRupees(payNow) ?? 0) < total && (
                        <p className="rounded-md bg-surface px-3 py-2 font-semibold">{t('purchases.will_owe', { amount: formatMoney(total - (parseRupees(payNow) ?? 0)) })}</p>
                    )}
                    <div className="flex justify-end gap-3 border-t border-line pt-4">
                        <Button variant="secondary" onClick={() => setConfirming(false)} disabled={busy !== null}>
                            {t('common.cancel')}
                        </Button>
                        <Button variant="success" size="lg" onClick={() => void onConfirm()} busy={busy === 'confirm'}>
                            {t('purchases.confirm_button')}
                        </Button>
                    </div>
                </div>
            </Modal>
        </div>
    );
}
