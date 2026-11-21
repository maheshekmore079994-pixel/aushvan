import { useQueryClient } from '@tanstack/react-query';
import { Minus, Plus, Search, Trash2, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ScanButton, type ScanResult } from '@/components/CameraScanner';
import { Alert, Badge, Button } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { productKeys, useUnitLabel } from '@/features/products/api';
import { TypeBadge } from '@/features/products/badges';
import { formatMoney } from '@/lib/money';
import { formatExpiry, formatQty } from '@/lib/qty';
import { useIsNarrow } from '@/lib/screen';
import { ulid } from '@/lib/ulid';
import { saleKeys, searchProducts, usePreview, useQuickItems, type CartCustomer, type CartLine, type PosProduct } from './api';
import { CustomerPicker } from './CustomerPicker';
import { PayDialog } from './PayDialog';

/**
 * A number box that can be emptied while typing ("5" → "" → "20"), unlike one that snaps back to 1.
 * The quantity changes only when a whole number of 1 or more is in the box; leaving it empty restores the last one.
 */
export function QtyBox({ value, onChange, label, className, max = 100000 }: { value: number; onChange: (qty: number) => void; label: string; className?: string; max?: number }) {
    const [text, setText] = useState<string | null>(null);

    return (
        <input
            aria-label={label}
            inputMode="numeric"
            value={text ?? String(value)}
            onChange={(event) => {
                const digits = event.target.value.replace(/\D/g, '').slice(0, 7);
                setText(digits);
                const qty = Number(digits);
                if (digits !== '' && qty >= 1) onChange(Math.min(qty, max));
            }}
            onBlur={() => setText(null)}
            onFocus={(event) => event.target.select()}
            className={className}
        />
    );
}

type Cart = { id: string; lines: CartLine[]; customer: CartCustomer | null };

/** One saved bill-in-progress per login on this PC. */
const storageKey = (userId: number) => `rmgsms.cart.${userId}`;
const newCart = (): Cart => ({ id: ulid(), lines: [], customer: null });

/** The bill in progress survives a closed browser or a power cut. */
function loadCart(userId: number): Cart {
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey(userId)) ?? 'null') as Cart | null;
        if (saved && typeof saved.id === 'string' && Array.isArray(saved.lines)) return saved;
    } catch {
        // Unreadable saved cart: start a new one.
    }
    return newCart();
}

export function PosPage() {
    const { t, i18n } = useTranslation();
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const unit = useUnitLabel();
    const searchBox = useRef<HTMLInputElement>(null);
    const { user, can } = useSession();
    const compact = useIsNarrow(1023); // phone or tablet: the bottom bar is showing
    const [cart, setCart] = useState<Cart>(() => loadCart(user.id));
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PosProduct[]>([]);
    const [highlight, setHighlight] = useState(0);
    const [paying, setPaying] = useState(false);
    const [pickingCustomer, setPickingCustomer] = useState(false);
    const [unknownCode, setUnknownCode] = useState<string | null>(null); // a scanned number nobody has; offers "Add product"
    const looksLikeBarcode = (text: string) => /^\d{6,}$/.test(text);
    const addProductWith = (code: string) => navigate(`/products/new?barcode=${encodeURIComponent(code)}&then=sale`);
    const quick = useQuickItems();
    const preview = usePreview(cart.lines);
    const cartNow = useRef(cart); // read by the camera scanner, whose answers arrive between renders
    cartNow.current = cart;

    useEffect(() => {
        try {
            localStorage.setItem(storageKey(user.id), JSON.stringify(cart));
        } catch {
            // Storage full or blocked: the cart still works for this visit.
        }
    }, [cart, user.id]);

    // Search as you type. Each answer is used only if it is for the text still in the box.
    useEffect(() => {
        const text = query.trim();
        if (text.length < 2) {
            setResults([]);
            return;
        }
        let current = true;
        const timer = setTimeout(() => {
            searchProducts(text)
                .then((r) => {
                    if (current) {
                        setResults(r.data);
                        setHighlight(0);
                    }
                })
                .catch(() => current && setResults([]));
        }, 200);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [query]);

    /** Adds one to the bill. False when there is none to sell; `quiet` leaves the telling to the caller. */
    function add(product: PosProduct, unitKind: 'pack' | 'base' = 'pack', keepBox = false, quiet = false): boolean {
        if (product.stock_qty <= 0) {
            if (!quiet) toast('error', t('pos.out_of_stock', { name: product.name }));
            return false;
        }
        setCart((c) => {
            const existing = c.lines.find((l) => l.product.id === product.id && l.unit === unitKind && l.batch_id === null);
            return {
                ...c,
                lines: existing
                    ? c.lines.map((l) => (l === existing ? { ...l, qty: l.qty + 1 } : l))
                    : [...c.lines, { key: ulid(), product, unit: unitKind, qty: 1, batch_id: null, discount: '' }],
            };
        });
        if (!keepBox) {
            setQuery('');
            setResults([]);
        }
        searchBox.current?.focus();
        return true;
    }

    /**
     * A barcode read by the camera. Only an exact barcode match is added: a number must never be searched
     * as a name. The answer is shown inside the scanner, which stays open for the next pack.
     */
    async function onCameraCode(code: string, stillOpen: () => boolean): Promise<ScanResult | void> {
        try {
            const found = await searchProducts(code);
            // The scanner was closed while the lookup was on its way: the operator has moved on, add nothing.
            if (!stillOpen()) return;
            const product = found.matched_barcode ? found.data[0] : undefined;
            if (!product) {
                setUnknownCode(code);
                return { ok: false, text: t('scan.unknown', { code }), action: can('products.manage') ? { label: t('pos.add_with_barcode'), run: () => addProductWith(code) } : undefined };
            }
            setUnknownCode(null);
            if (product.stock_qty <= 0) return { ok: false, text: t('pos.out_of_stock', { name: product.name }) };
            const line = cartNow.current.lines.find((l) => l.product.id === product.id && l.unit === found.barcode_unit && l.batch_id === null);
            const qty = (line?.qty ?? 0) + 1;
            // One more than the shelf holds is refused here, with the reason, instead of a green "added".
            const perUnit = found.barcode_unit === 'pack' ? Math.max(product.pack_size, 1) : 1;
            if (qty * perUnit > product.stock_qty) {
                const unitCode = found.barcode_unit === 'pack' ? product.pricing_unit : product.base_unit;
                const left = Math.floor(product.stock_qty / perUnit);
                return { ok: false, text: `${product.name}: ${t('pos.only_left', { qty: `${left} ${unit(unitCode, left)}` })}` };
            }
            add(product, found.barcode_unit, true, true);
            return { ok: true, text: t('scan.added', { name: product.name, qty }) };
        } catch {
            return stillOpen() ? { ok: false, text: t('app.no_connection') } : undefined;
        }
    }

    /** Enter in the search box: a scanner sends the barcode then Enter; a person picks the highlighted row. */
    async function onSearchKey(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlight((h) => Math.min(h + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlight((h) => Math.max(h - 1, 0));
        } else if (event.key === 'Escape') {
            setQuery('');
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const text = query.trim();
            if (text === '') return;
            const picked = highlight;
            setUnknownCode(null);
            // Emptied before the lookup: a second scan may arrive while this one is still being looked up.
            setQuery('');
            setResults([]);
            try {
                const found = await searchProducts(text);
                if (found.matched_barcode && found.data[0]) add(found.data[0], found.barcode_unit, true);
                else if (found.data.length > 0) add(found.data[Math.min(picked, found.data.length - 1)], 'pack', true);
                else if (looksLikeBarcode(text)) setUnknownCode(text);
                else toast('error', t('pos.not_found', { text }));
            } catch {
                toast('error', t('app.no_connection'));
            }
        }
    }

    const update = (key: string, change: Partial<CartLine>) => setCart((c) => ({ ...c, lines: c.lines.map((l) => (l.key === key ? { ...l, ...change } : l)) }));
    const remove = (key: string) => setCart((c) => ({ ...c, lines: c.lines.filter((l) => l.key !== key) }));
    const clear = () => setCart(newCart());

    const priced = cart.lines.length > 0 ? preview.data : undefined;
    const canPay = cart.lines.length > 0 && priced !== undefined && priced.errors.length === 0 && !preview.isPlaceholderData; // never pay against totals of an older cart

    // F2 search, F4 customer, F9 pay — each is also a visible button.
    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            if (paying || pickingCustomer || document.querySelector('dialog[open], [role="dialog"][aria-modal="true"]')) return;
            if (event.key === 'F2') {
                event.preventDefault();
                searchBox.current?.focus();
            } else if (event.key === 'F4') {
                event.preventDefault();
                setPickingCustomer(true);
            } else if (event.key === 'F9' && canPay) {
                event.preventDefault();
                setPaying(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [paying, pickingCustomer, canPay]);

    const lineTotal = (index: number) => priced?.lines.filter((l) => l.index === index).reduce((sum, l) => sum + l.line_total_paise, 0);
    const lineError = (index: number) => priced?.errors.find((e) => e.index === index);
    const totals = priced?.totals;
    const tax = totals ? totals.cgst_paise + totals.sgst_paise + totals.igst_paise + totals.cess_paise : 0;

    return (
        <div className={`grid gap-4 lg:grid-cols-[1fr_22rem] ${cart.lines.length > 0 ? 'pb-16 lg:pb-0' : ''}`}>
            <section className="flex min-w-0 flex-col gap-3">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-6 -translate-y-1/2 text-ink-soft" aria-hidden />
                    <input
                        ref={searchBox}
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={onSearchKey}
                        autoFocus
                        aria-label={t('pos.search')}
                        placeholder={`${t('pos.search')}  (F2)`}
                        className="block min-h-14 w-full rounded-lg border-2 border-primary bg-white pl-12 pr-14 text-lg"
                    />
                    {/* On a PC the cursor goes back to the search box, where a plug-in scanner types; on a phone that would only raise the keyboard. */}
                    <ScanButton onCode={onCameraCode} onClosed={() => !compact && searchBox.current?.focus()} className="absolute right-1.5 top-1.5 size-11" />
                    {results.length > 0 && (
                        <ul className="absolute z-10 mt-1 max-h-96 w-full overflow-y-auto rounded-lg border border-line bg-white shadow-lg" role="listbox">
                            {results.map((product, index) => (
                                <li key={product.id} role="option" aria-selected={index === highlight}>
                                    <button
                                        type="button"
                                        onClick={() => add(product)}
                                        className={`flex min-h-14 w-full items-center gap-3 px-3 py-2 text-left ${index === highlight ? 'bg-primary-soft' : 'hover:bg-primary-soft/40'}`}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2 font-semibold">
                                                {product.name} <TypeBadge type={product.type} />
                                                {product.needs_prescription && <Badge tone="danger">Rx</Badge>}
                                            </span>
                                            <span className="block text-sm text-ink-soft">
                                                {[product.generic_name, product.strength, product.name_local, product.rack_location].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                        <span className="whitespace-nowrap text-right">
                                            <span className="block font-bold">{formatMoney(product.price_paise)}</span>
                                            <span className={`block text-sm ${product.stock_qty > 0 ? 'text-ink-soft' : 'font-semibold text-danger'}`}>
                                                {product.stock_qty > 0
                                                    ? formatQty(product.stock_qty, product.pack_size, product.base_unit, product.pricing_unit, unit)
                                                    : t('stock.status.out')}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {unknownCode && (
                    <div role="status" className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-warning/40 bg-warning-soft px-3 py-2">
                        <span className="font-medium text-warning">{t('scan.unknown', { code: unknownCode })}</span>
                        <span className="flex gap-2">
                            {can('products.manage') && (
                                <Button variant="secondary" onClick={() => addProductWith(unknownCode)}>
                                    {t('pos.add_with_barcode')}
                                </Button>
                            )}
                            <Button variant="ghost" aria-label={t('common.close')} onClick={() => setUnknownCode(null)} icon={<X className="size-5" aria-hidden />} />
                        </span>
                    </div>
                )}
                {cart.lines.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-line bg-white p-6 text-center text-ink-soft">{t('pos.empty')}</div>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {cart.lines.map((line, index) => {
                            const p = line.product;
                            const problem = lineError(index);
                            const total = lineTotal(index);
                            const usedBatches = priced?.lines.filter((l) => l.index === index) ?? [];
                            return (
                                <li key={line.key} className={`rounded-lg border bg-white p-3 ${problem ? 'border-danger' : 'border-line'}`}>
                                    <div className="flex flex-wrap items-center gap-3">
                                        <div className="min-w-40 flex-1">
                                            <p className="flex flex-wrap items-center gap-2 font-semibold">
                                                {p.name} <TypeBadge type={p.type} />
                                                {p.needs_prescription && <Badge tone="danger">Rx</Badge>}
                                            </p>
                                            <p className="text-sm text-ink-soft">
                                                {usedBatches.map((b) => [b.batch_no, formatExpiry(b.expiry_date, i18n.language)].filter((x) => x && x !== '—').join(' · ')).filter(Boolean).join(' + ')}
                                            </p>
                                        </div>

                                        {p.allow_loose_sale && (
                                            <select
                                                aria-label={t('stock.unit')}
                                                value={line.unit}
                                                onChange={(e) => update(line.key, { unit: e.target.value as 'pack' | 'base' })}
                                                className="min-h-11 rounded-md border border-line bg-white px-2"
                                            >
                                                <option value="pack">{unit(p.pricing_unit)}</option>
                                                <option value="base">{unit(p.base_unit)}</option>
                                            </select>
                                        )}
                                        {!p.allow_loose_sale && <span className="text-ink-soft">{unit(p.pricing_unit)}</span>}

                                        <div className="flex items-center">
                                            <Button
                                                variant="secondary"
                                                aria-label={t('pos.less')}
                                                onClick={() => (line.qty > 1 ? update(line.key, { qty: line.qty - 1 }) : remove(line.key))}
                                                icon={<Minus className="size-5" aria-hidden />}
                                            />
                                            <QtyBox label={t('stock.quantity')} value={line.qty} onChange={(qty) => update(line.key, { qty })} className="mx-1 min-h-11 w-20 rounded-md border border-line text-center text-lg font-bold" />
                                            <Button variant="secondary" aria-label={t('pos.more')} onClick={() => update(line.key, { qty: line.qty + 1 })} icon={<Plus className="size-5" aria-hidden />} />
                                        </div>

                                        <p className="w-24 text-right text-lg font-bold">{total !== undefined && !problem ? formatMoney(total) : '—'}</p>
                                        <Button variant="ghost" aria-label={t('pos.remove', { name: p.name })} onClick={() => remove(line.key)} icon={<Trash2 className="size-5 text-danger" aria-hidden />} />
                                    </div>

                                    {p.tracks_batch && p.batches.length > 1 && (
                                        <label className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                            <span className="text-ink-soft">{t('stock.batch_no')}:</span>
                                            <select
                                                value={line.batch_id ?? ''}
                                                onChange={(e) => update(line.key, { batch_id: e.target.value === '' ? null : Number(e.target.value) })}
                                                className="min-h-9 rounded-md border border-line bg-white px-2"
                                            >
                                                <option value="">{t('pos.batch_auto')}</option>
                                                {p.batches.map((b) => (
                                                    <option key={b.id} value={b.id}>
                                                        {b.batch_no} · {formatExpiry(b.expiry_date, i18n.language)} · {formatQty(b.qty, p.pack_size, p.base_unit, p.pricing_unit, unit)}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    )}

                                    {problem && (
                                        <p role="alert" className="mt-2 font-semibold text-danger">
                                            {problem.code === 'insufficient_stock'
                                                ? t('pos.only_left', { qty: `${problem.available ?? 0} ${unit(problem.unit ?? p.pricing_unit)}` })
                                                : t(`errors.${problem.code}`, { defaultValue: problem.code })}
                                        </p>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {quick.data && quick.data.length > 0 && (
                    <div>
                        <p className="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-soft">{t('pos.quick_items')}</p>
                        <div className="flex flex-wrap gap-2">
                            {quick.data.map((product) => (
                                <button
                                    key={product.id}
                                    type="button"
                                    onClick={() => add(product)}
                                    className="min-h-14 rounded-lg border border-line bg-white px-4 text-left font-semibold hover:border-primary hover:bg-primary-soft"
                                >
                                    {product.name_local ?? product.name}
                                    <span className="block text-sm font-normal text-ink-soft">
                                        {formatMoney(product.price_paise)} / {unit(product.pricing_unit, 1)}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </section>

            <aside className="flex h-fit flex-col gap-3 rounded-lg border border-line bg-white p-4 lg:sticky lg:top-4">
                <div className="flex items-center gap-2">
                    <UserRound className="size-6 shrink-0 text-ink-soft" aria-hidden />
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold">{cart.customer?.name ?? t('pos.walk_in')}</p>
                        {cart.customer && cart.customer.balance_paise > 0 && (
                            <p className="text-sm font-semibold text-warning">{t('pos.owes', { amount: formatMoney(cart.customer.balance_paise) })}</p>
                        )}
                    </div>
                    {cart.customer && (
                        <Button variant="ghost" aria-label={t('pos.remove_customer')} onClick={() => setCart((c) => ({ ...c, customer: null }))} icon={<X className="size-5" aria-hidden />} />
                    )}
                    <Button variant="secondary" onClick={() => setPickingCustomer(true)}>
                        {t('pos.customer')} (F4)
                    </Button>
                </div>

                <dl className="flex flex-col gap-1 border-t border-line pt-3">
                    <div className="flex justify-between">
                        <dt className="text-ink-soft">{t('pos.items')}</dt>
                        <dd className="font-semibold">{cart.lines.length}</dd>
                    </div>
                    {totals && totals.discount_paise > 0 && (
                        <div className="flex justify-between">
                            <dt className="text-ink-soft">{t('pos.discount')}</dt>
                            <dd className="font-semibold">− {formatMoney(totals.discount_paise)}</dd>
                        </div>
                    )}
                    {tax > 0 && (
                        <div className="flex justify-between text-sm">
                            <dt className="text-ink-soft">{t('pos.includes_gst')}</dt>
                            <dd>{formatMoney(tax)}</dd>
                        </div>
                    )}
                </dl>

                <div className="rounded-lg bg-rail px-4 py-4 text-white">
                    <p className="text-sm uppercase tracking-wide text-white/65">{t('pos.total')}</p>
                    <p className="text-4xl font-bold" aria-live="polite">
                        {formatMoney(totals?.total_paise ?? 0)}
                    </p>
                </div>

                {preview.isError && cart.lines.length > 0 && <Alert>{t('pos.error_network')}</Alert>}

                <Button variant="success" size="lg" disabled={!canPay} onClick={() => setPaying(true)}>
                    {t('pos.pay')} (F9)
                </Button>
                <Button variant="secondary" disabled={cart.lines.length === 0} onClick={clear}>
                    {t('pos.clear')}
                </Button>
            </aside>

            {/* Phones: the cart can be long, so the total and Pay ride above the bottom bar. */}
            {compact && cart.lines.length > 0 && (
                <div className="fixed inset-x-0 bottom-14 z-10 flex items-center gap-3 border-t border-line bg-white px-3 py-2 shadow-pop lg:hidden print:hidden">
                    <span className="min-w-0 flex-1">
                        <span className="block text-xs uppercase tracking-wide text-ink-soft">{t('pos.total')}</span>
                        <span className="block text-xl font-bold leading-tight">{formatMoney(totals?.total_paise ?? 0)}</span>
                    </span>
                    <Button variant="success" disabled={!canPay} onClick={() => setPaying(true)} className="min-w-28">
                        {t('pos.pay_short')}
                    </Button>
                </div>
            )}

            <CustomerPicker
                open={pickingCustomer}
                onClose={() => setPickingCustomer(false)}
                onPick={(customer) => {
                    setCart((c) => ({ ...c, customer: { id: customer.id, name: customer.name, mobile: customer.mobile, balance_paise: customer.balance_paise, credit_limit_paise: customer.credit_limit_paise } }));
                    setPickingCustomer(false);
                }}
            />

            {priced && (
                <PayDialog
                    open={paying}
                    suspended={pickingCustomer}
                    cartId={cart.id}
                    lines={cart.lines}
                    preview={priced}
                    customer={cart.customer}
                    onChooseCustomer={() => setPickingCustomer(true)}
                    onClose={() => setPaying(false)}
                    onDone={(bill, differs) => {
                        setPaying(false);
                        setCart(newCart());
                        void queryClient.invalidateQueries({ queryKey: productKeys.all });
                        void queryClient.invalidateQueries({ queryKey: saleKeys.all });
                        void queryClient.invalidateQueries({ queryKey: saleKeys.customers });
                        void queryClient.invalidateQueries({ queryKey: ['pos'] });
                        void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
                        if (differs) toast('error', t('pos.saved_earlier', { number: bill.invoice_no }));
                        else toast('success', t('pos.saved', { number: bill.invoice_no }));
                        navigate(`/sales/${bill.id}?new=1`);
                    }}
                />
            )}
        </div>
    );
}
