import { clsx } from 'clsx';
import { Download, PackagePlus, Search } from 'lucide-react';
import { ScanButton } from '@/components/CameraScanner';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, Button, Card, Spinner } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { formatMoney } from '@/lib/money';
import { formatExpiry, formatQty } from '@/lib/qty';
import { useIsNarrow } from '@/lib/screen';
import { useProducts, useUnitLabel, type ProductFilters, type ProductType } from './api';
import { ScheduleBadge, StockBadge, TypeBadge } from './badges';

function useDebounced<T>(value: T, delay = 250): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

/**
 * Product list. With `type` fixed it is the Medical Stock or General Stock
 * screen; without it, it is the Products screen with a type switch.
 */
export function ProductsPage({ type, title }: { type?: ProductType; title: string }) {
    const { t, i18n } = useTranslation();
    const narrow = useIsNarrow();
    const { can } = useSession();
    const navigate = useNavigate();
    const unit = useUnitLabel();
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState<ProductType | ''>(type ?? '');
    const [stock, setStock] = useState<ProductFilters['stock']>('');
    const [page, setPage] = useState(1);
    const debouncedSearch = useDebounced(search);

    useEffect(() => setTypeFilter(type ?? ''), [type]);
    useEffect(() => setPage(1), [debouncedSearch, typeFilter, stock]);

    const products = useProducts({ search: debouncedSearch, type: typeFilter, stock, page });
    const canAdd = can('products.manage') || can('medicines.manage');

    const chip = (active: boolean) =>
        clsx('min-h-8 rounded-full border px-3 text-sm font-medium', active ? 'border-primary bg-primary text-white' : 'border-line bg-white text-ink-soft hover:bg-primary-soft/40');

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                <div className="flex gap-2">
                    <a
                        href="/api/v1/products/export"
                        className="inline-flex min-h-11 items-center gap-2 rounded-md border border-line bg-white px-4 font-semibold hover:bg-primary-soft/40"
                    >
                        <Download className="size-5" aria-hidden />
                        {t('products.export')}
                    </a>
                    {canAdd && (
                        <Button variant="success" onClick={() => navigate('/products/new')} icon={<PackagePlus className="size-5" aria-hidden />}>
                            {t('products.add')}
                        </Button>
                    )}
                </div>
            </div>

            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-6 -translate-y-1/2 text-ink-soft" aria-hidden />
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    autoFocus
                    aria-label={t('products.search')}
                    placeholder={t('products.search')}
                    className="block min-h-10 w-full rounded-lg border border-line bg-white pl-10 pr-12 text-base shadow-card"
                />
                {/* The scanned number goes into the search box; the list then shows that product. */}
                <ScanButton onCode={(code) => setSearch(code)} className="absolute right-1 top-1/2 size-9 -translate-y-1/2" />
            </div>

            <div className="flex flex-wrap gap-2">
                {!type &&
                    (['', 'medical', 'general'] as const).map((value) => (
                        <button key={value || 'all'} type="button" className={chip(typeFilter === value)} aria-pressed={typeFilter === value} onClick={() => setTypeFilter(value)}>
                            {value ? t(`products.type.${value}`) : t('products.all_types')}
                        </button>
                    ))}
                {!type && <span className="mx-1 w-px self-stretch bg-line" aria-hidden />}
                {(['', 'low', 'out'] as const).map((value) => (
                    <button key={value || 'any'} type="button" className={chip(stock === value)} aria-pressed={stock === value} onClick={() => setStock(value)}>
                        {value ? t(`stock.status.${value}`) : t('stock.any_stock')}
                    </button>
                ))}
            </div>

            {products.isError && <Alert>{t('app.error_generic')}</Alert>}
            {products.isPending && <Spinner label={t('common.loading')} />}

            {products.data && products.data.data.length === 0 && (
                <Card className="text-center">
                    <p className="text-lg font-semibold">{debouncedSearch || stock ? t('products.none_found') : t('products.empty')}</p>
                    {canAdd && !debouncedSearch && !stock && (
                        <Button className="mt-4" variant="success" onClick={() => navigate('/products/new')}>
                            {t('products.add')}
                        </Button>
                    )}
                </Card>
            )}

            {products.data && products.data.data.length > 0 && narrow && (
                <ul className="flex flex-col gap-2">
                    {products.data.data.map((product) => (
                        <li key={product.id}>
                            <Link to={`/products/${product.id}`} className="block rounded-xl border border-line/70 bg-white p-3 shadow-card">
                                <span className="flex items-start justify-between gap-3">
                                    <span className="min-w-0 font-semibold text-primary-dark">{product.name}</span>
                                    <span className="shrink-0 text-right">
                                        <span className="block font-bold">{formatMoney(product.selling_price_paise)}</span>
                                        <span className="block text-xs text-ink-soft">/ {unit(product.pricing_unit, 1)}</span>
                                    </span>
                                </span>
                                <span className="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                                    <TypeBadge type={product.type} />
                                    {product.generic_name && <span>{[product.generic_name, product.strength].filter(Boolean).join(' ')}</span>}
                                    {product.name_local && <span>{product.name_local}</span>}
                                    <ScheduleBadge code={product.schedule} isSet={product.schedule_set} />
                                </span>
                                <span className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                    <StockBadge status={product.stock_status} />
                                    <span className="font-medium">{formatQty(product.stock_qty, product.pack_size, product.base_unit, product.pricing_unit, unit)}</span>
                                    {product.nearest_expiry && <span className="text-ink-soft">{formatExpiry(product.nearest_expiry, i18n.language)}</span>}
                                    {product.rack_location && <span className="text-ink-soft">{product.rack_location}</span>}
                                </span>
                                {product.expired_qty > 0 && (
                                    <span className="mt-1 block text-sm font-semibold text-danger">
                                        {t('stock.expired_on_shelf', { qty: formatQty(product.expired_qty, product.pack_size, product.base_unit, product.pricing_unit, unit) })}
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {products.data && products.data.data.length > 0 && !narrow && (
                <Card className="overflow-x-auto p-0 sm:p-0">
                    <table className="w-full min-w-[720px] text-left">
                        <thead className="border-b border-line bg-surface text-sm text-ink-soft">
                            <tr>
                                <th className="px-3 py-2 font-semibold">{t('products.name')}</th>
                                <th className="px-3 py-2 text-right font-semibold">{t('products.price')}</th>
                                <th className="px-3 py-2 font-semibold">{t('stock.in_stock')}</th>
                                <th className="px-3 py-2 font-semibold">{t('stock.next_expiry')}</th>
                                <th className="px-3 py-2 font-semibold">{t('products.rack')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {products.data.data.map((product) => (
                                <tr key={product.id} className="hover:bg-primary-soft/40">
                                    <td className="px-3 py-2">
                                        <Link to={`/products/${product.id}`} className="font-semibold text-primary-dark hover:underline">
                                            {product.name}
                                        </Link>
                                        <div className="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                                            <TypeBadge type={product.type} />
                                            {product.generic_name && <span>{[product.generic_name, product.strength].filter(Boolean).join(' ')}</span>}
                                            {product.name_local && <span>{product.name_local}</span>}
                                            <ScheduleBadge code={product.schedule} isSet={product.schedule_set} />
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2 text-right">
                                        <span className="font-semibold">{formatMoney(product.selling_price_paise)}</span>
                                        <span className="ml-1 text-xs text-ink-soft">/ {unit(product.pricing_unit, 1)}</span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <StockBadge status={product.stock_status} />
                                        <span className="ml-2 font-medium">
                                            {formatQty(product.stock_qty, product.pack_size, product.base_unit, product.pricing_unit, unit)}
                                        </span>
                                        {product.expired_qty > 0 && (
                                            <span className="block text-sm font-semibold text-danger">
                                                {t('stock.expired_on_shelf', {
                                                    qty: formatQty(product.expired_qty, product.pack_size, product.base_unit, product.pricing_unit, unit),
                                                })}
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-2">{formatExpiry(product.nearest_expiry, i18n.language)}</td>
                                    <td className="px-3 py-2">{product.rack_location ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}

            {products.data && products.data.meta.last_page > 1 && (
                <div className="flex items-center justify-center gap-3">
                    <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        {t('common.previous')}
                    </Button>
                    <span className="font-medium">{t('common.page_of', { page: products.data.meta.page, pages: products.data.meta.last_page })}</span>
                    <Button variant="secondary" disabled={page >= products.data.meta.last_page} onClick={() => setPage(page + 1)}>
                        {t('common.next')}
                    </Button>
                </div>
            )}
            {products.data && <p className="text-center text-sm text-ink-soft">{t('products.count', { count: products.data.meta.total })}</p>}
        </div>
    );
}
