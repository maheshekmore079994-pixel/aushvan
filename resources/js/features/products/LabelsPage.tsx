import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import JsBarcode from 'jsbarcode';
import { Minus, Plus, Printer, ScanBarcode, Search, Trash2, Wand2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { Alert, Button, Card, CheckField, EmptyState, PageHeader, SelectField } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { useProducts, useUnitLabel, type ProductType } from './api';
import { TypeBadge } from './badges';

type LabelProduct = {
    id: number;
    name: string;
    name_local: string | null;
    type: ProductType;
    mrp_paise: number;
    selling_price_paise: number;
    pricing_unit: string;
    barcode: string | null;
};

/**
 * Label sizes. Sheets are A4 sticker paper for an ordinary printer; rolls are for a label printer,
 * one label per page. Sizes are the common ones sold in India; the printed result should be checked
 * against the shop's own sticker paper before a full run.
 */
const LAYOUTS = {
    a4_65: { columns: 5, width: 38, height: 21, sheet: true },
    a4_40: { columns: 4, width: 48, height: 25, sheet: true },
    a4_24: { columns: 3, width: 64, height: 34, sheet: true },
    roll_50x25: { columns: 1, width: 50, height: 25, sheet: false },
    roll_38x25: { columns: 1, width: 38, height: 25, sheet: false },
} as const;

type LayoutName = keyof typeof LAYOUTS;

/** Draws one barcode. EAN-13 when the number is a valid one (it scans fastest); otherwise Code 128, which takes anything. */
export function BarcodeSvg({ value, height = 34 }: { value: string; height?: number }) {
    const ref = useRef<SVGSVGElement>(null);

    useEffect(() => {
        if (!ref.current) return;
        const draw = (format: 'EAN13' | 'CODE128') =>
            JsBarcode(ref.current, value, { format, height, width: 1.4, margin: 0, fontSize: 11, textMargin: 1, displayValue: true });
        try {
            draw(/^\d{13}$/.test(value) ? 'EAN13' : 'CODE128');
        } catch {
            // A 13-digit number with a wrong check digit is not an EAN; Code 128 still prints it.
            try {
                draw('CODE128');
            } catch {
                ref.current.replaceChildren();
            }
        }
    }, [value, height]);

    return <svg ref={ref} role="img" aria-label={value} className="max-w-full" />;
}

function useDebounced<T>(value: T, delay = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

/** Print barcode stickers: pick products, say how many of each, choose the sticker size, print. */
export function LabelsPage() {
    const { t } = useTranslation();
    const unit = useUnitLabel();
    const queryClient = useQueryClient();
    const [params] = useSearchParams();
    const [counts, setCounts] = useState<Record<number, number>>(() => {
        const first = Number(params.get('product'));
        return first > 0 ? { [first]: 1 } : {};
    });
    const [search, setSearch] = useState('');
    const [layout, setLayout] = useState<LayoutName>('a4_40');
    const [showPrice, setShowPrice] = useState(true);
    const [showShop, setShowShop] = useState(false);
    const [shopName, setShopName] = useState<string | null>(null);

    const term = useDebounced(search.trim());
    const found = useProducts({ search: term, type: '', stock: '', page: 1 }, term.length >= 2);
    const ids = Object.keys(counts).map(Number);
    const labelsKey = ['labels', [...ids].sort((a, b) => a - b).join(',')];
    const chosen = useQuery({
        queryKey: labelsKey,
        queryFn: () => api<{ data: LabelProduct[] }>(`/products/labels?${ids.map((id) => `ids[]=${id}`).join('&')}`).then((r) => r.data),
        enabled: ids.length > 0,
        placeholderData: (previous) => previous,
    });
    const makeBarcode = useMutation({
        mutationFn: (id: number) => api<LabelProduct>(`/products/${id}/barcode`, { method: 'POST' }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['labels'] }),
    });

    useEffect(() => {
        if (showShop && shopName === null) api<{ name: string }>('/store').then((shop) => setShopName(shop.name)).catch(() => setShowShop(false));
    }, [showShop, shopName]);

    const products = (chosen.data ?? []).filter((product) => counts[product.id] !== undefined);
    const setCount = (id: number, count: number) => setCounts((current) => ({ ...current, [id]: Math.min(Math.max(count, 1), 500) }));
    const remove = (id: number) => setCounts(({ [id]: _removed, ...rest }) => rest);
    const size = LAYOUTS[layout];
    const labels = products.filter((product) => product.barcode).flatMap((product) => Array.from({ length: counts[product.id] }, () => product));
    const missing = products.filter((product) => !product.barcode);
    const showResults = term.length >= 2 && found.data;

    return (
        <div className="flex flex-col gap-4">
            <PageHeader title={t('labels.title')} subtitle={t('labels.intro')}>
                <Button variant="success" disabled={labels.length === 0} onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                    {t('labels.print_n', { count: labels.length })}
                </Button>
            </PageHeader>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,26rem)_1fr] print:block">
                <div className="flex flex-col gap-4 print:hidden">
                    <Card>
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                            <input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                aria-label={t('products.search')}
                                placeholder={t('products.search')}
                                className="block min-h-12 w-full rounded-lg border border-line bg-white pl-10 pr-3"
                            />
                        </div>
                        {showResults && (
                            <ul className="mt-2 flex max-h-72 flex-col overflow-y-auto">
                                {found.data.data.length === 0 && <li className="px-2 py-3 text-ink-soft">{t('products.none_found')}</li>}
                                {found.data.data.map((product) => (
                                    <li key={product.id}>
                                        <button
                                            type="button"
                                            disabled={counts[product.id] !== undefined}
                                            onClick={() => {
                                                setCount(product.id, 1);
                                                setSearch('');
                                            }}
                                            className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left hover:bg-primary-soft disabled:opacity-50"
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <TypeBadge type={product.type} />
                                                <span className="truncate font-medium">{product.name}</span>
                                            </span>
                                            <Plus className="size-5 shrink-0 text-primary" aria-hidden />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {products.length > 0 && (
                        <Card title={t('labels.chosen')}>
                            <ul className="flex flex-col divide-y divide-line">
                                {products.map((product) => (
                                    <li key={product.id} className="flex flex-wrap items-center gap-2 py-2">
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">{product.name}</span>
                                            <span className="block text-sm text-ink-soft">{product.barcode ?? t('labels.no_barcode')}</span>
                                        </span>
                                        {product.barcode ? (
                                            <span className="inline-flex items-center rounded-lg border border-line">
                                                <button type="button" className="flex size-10 items-center justify-center" onClick={() => setCount(product.id, counts[product.id] - 1)} aria-label={t('pos.less')}>
                                                    <Minus className="size-4" aria-hidden />
                                                </button>
                                                <input
                                                    inputMode="numeric"
                                                    aria-label={t('labels.count_for', { name: product.name })}
                                                    value={counts[product.id]}
                                                    onChange={(e) => setCount(product.id, Number(e.target.value.replace(/\D/g, '')) || 1)}
                                                    className="w-12 bg-transparent text-center font-semibold focus:outline-none"
                                                />
                                                <button type="button" className="flex size-10 items-center justify-center" onClick={() => setCount(product.id, counts[product.id] + 1)} aria-label={t('pos.more')}>
                                                    <Plus className="size-4" aria-hidden />
                                                </button>
                                            </span>
                                        ) : (
                                            <Button variant="secondary" busy={makeBarcode.isPending && makeBarcode.variables === product.id} onClick={() => makeBarcode.mutate(product.id)} icon={<Wand2 className="size-4" aria-hidden />}>
                                                {t('labels.make_barcode')}
                                            </Button>
                                        )}
                                        <button type="button" className="flex size-10 items-center justify-center rounded-lg text-ink-soft hover:bg-danger-soft hover:text-danger" onClick={() => remove(product.id)} aria-label={t('pos.remove', { name: product.name })}>
                                            <Trash2 className="size-5" aria-hidden />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card title={t('labels.settings')}>
                        <div className="flex flex-col gap-3">
                            <SelectField
                                label={t('labels.size')}
                                value={layout}
                                onChange={(value) => setLayout(value as LayoutName)}
                                options={(Object.keys(LAYOUTS) as LayoutName[]).map((name) => ({ value: name, label: t(`labels.layout.${name}`) }))}
                                help={t('labels.size_help')}
                            />
                            <CheckField label={t('labels.show_price')} checked={showPrice} onChange={setShowPrice} />
                            <CheckField label={t('labels.show_shop')} checked={showShop} onChange={setShowShop} />
                        </div>
                    </Card>
                </div>

                <div>
                    {makeBarcode.isError && <Alert>{makeBarcode.error instanceof ApiError && makeBarcode.error.status === 403 ? t('app.forbidden') : t('app.no_connection_help')}</Alert>}
                    {missing.length > 0 && labels.length > 0 && (
                        <div className="mb-3 print:hidden">
                            <Alert tone="warning">{t('labels.missing_note', { count: missing.length })}</Alert>
                        </div>
                    )}
                    {labels.length === 0 ? (
                        <div className="print:hidden">
                            <EmptyState icon={<ScanBarcode className="size-8" />} title={t('labels.empty')}>
                                <p className="max-w-md text-ink-soft">{t('labels.empty_help')}</p>
                            </EmptyState>
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border border-line/70 bg-white p-4 shadow-card print:overflow-visible print:rounded-none print:border-0 print:p-0 print:shadow-none">
                            <div
                                className="label-sheet grid justify-start gap-1"
                                style={{ gridTemplateColumns: `repeat(${size.columns}, ${size.width}mm)` }}
                                data-testid="label-sheet"
                            >
                                {labels.map((product, index) => (
                                    <div
                                        key={`${product.id}-${index}`}
                                        className="flex flex-col items-center justify-center overflow-hidden border border-dashed border-line px-1 text-center leading-tight text-black"
                                        style={{ width: `${size.width}mm`, height: `${size.height}mm`, breakAfter: size.sheet ? undefined : 'page' }}
                                    >
                                        {showShop && shopName && <span className="w-full truncate text-[7px] font-semibold uppercase">{shopName}</span>}
                                        <span className="w-full truncate text-[9px] font-semibold">{product.name}</span>
                                        <BarcodeSvg value={product.barcode as string} height={size.height >= 30 ? 44 : size.height >= 25 ? 30 : 22} />
                                        {showPrice && (
                                            <span className="text-[9px] font-bold">
                                                {product.mrp_paise > 0 ? `MRP ${formatMoney(product.mrp_paise)}` : formatMoney(product.selling_price_paise)}
                                                <span className="font-normal"> / {unit(product.pricing_unit, 1)}</span>
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
