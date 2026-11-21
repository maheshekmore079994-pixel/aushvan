import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';

export type ProductType = 'medical' | 'general';
export type StockStatus = 'ok' | 'low' | 'out';
export type ExpiryStatus = 'none' | 'expired' | 'within_30' | 'within_60' | 'within_90' | 'ok';

export type ProductRow = {
    id: number;
    type: ProductType;
    name: string;
    name_local: string | null;
    sku: string;
    brand: string | null;
    category: string | null;
    generic_name: string | null;
    strength: string | null;
    schedule: string | null;
    schedule_set: boolean;
    mrp_paise: number;
    selling_price_paise: number;
    base_unit: string;
    pricing_unit: string;
    pack_size: number;
    rack_location: string | null;
    is_active: boolean;
    min_stock_qty: number;
    stock_qty: number;
    expired_qty: number;
    unsellable_qty: number;
    nearest_expiry: string | null;
    stock_status: StockStatus;
};

export type Batch = {
    id: number;
    batch_no: string | null;
    expiry_date: string | null;
    received_date: string;
    mrp_paise: number;
    selling_price_paise: number;
    qty_sellable: number;
    qty_unsellable: number;
    days_to_expiry: number | null;
    expiry_status: ExpiryStatus;
    cost_paise?: number;
    value_paise?: number;
};

export type BatchRow = Batch & {
    product_id: number;
    product_name: string;
    product_type: ProductType;
    base_unit: string;
    pricing_unit: string;
    pack_size: number;
};

export type MedicineDetails = {
    generic_name: string | null;
    composition_text: string | null;
    strength: string | null;
    dosage_form: string | null;
    drug_schedule_id: number | null;
    is_ndps: boolean;
    storage_instructions: string | null;
};

export type ProductDetail = ProductRow & {
    category_id: number | null;
    manufacturer: string | null;
    hsn_code: string | null;
    tax_rate_id: number;
    tax_rate: string;
    base_unit_id: number;
    pack_unit_id: number | null;
    allow_loose_sale: boolean;
    min_stock: number;
    tracks_batch: boolean;
    tracks_expiry: boolean;
    is_quick_item: boolean;
    version: number;
    has_stock_history: boolean;
    barcodes: { barcode: string; unit: 'pack' | 'base'; is_internal: boolean }[];
    medicine: MedicineDetails | null;
    batches: Batch[];
};

export type Movement = {
    id: number;
    at: string;
    type: string;
    bucket: 'sellable' | 'unsellable';
    qty_delta: number;
    balance_after: number;
    batch_no: string | null;
    user: string;
    note: string | null;
};

export type CatalogOptions = {
    units: { id: number; code: string; name: string; name_local: string | null }[];
    tax_rates: { id: number; name: string; rate_percent: string; kind: string }[];
    drug_schedules: { id: number; code: string; name: string; requires_prescription: boolean; warning_text: string | null }[];
    categories: { id: number; name: string; name_local: string | null; scope: 'medical' | 'general' | 'both' }[];
    manufacturers: string[];
    dosage_forms: string[];
};

export type StockSummary = {
    products: number;
    medical_products: number;
    general_products: number;
    low_stock: number;
    out_of_stock: number;
    expired_batches: number;
    expiring_30: number;
    expiring_60: number;
    expiring_90: number;
    schedule_not_set: number;
    stock_value_paise?: number;
    ledger_mismatches?: number;
};

export type ProductFilters = { search: string; type: ProductType | ''; stock: 'low' | 'out' | ''; page: number };

type Paged<T> = { data: T[]; meta: { total: number; page: number; last_page: number } };

function query(params: Record<string, string | number | undefined>): string {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== '') search.set(key, String(value));
    }
    const text = search.toString();
    return text ? `?${text}` : '';
}

export const productKeys = {
    all: ['products'] as const,
    list: (filters: ProductFilters) => ['products', 'list', filters] as const,
    detail: (id: number) => ['products', 'detail', id] as const,
    movements: (id: number) => ['products', 'movements', id] as const,
    batches: (status: string, days?: number) => ['products', 'batches', status, days] as const,
    summary: ['products', 'summary'] as const,
    options: ['catalog', 'options'] as const,
};

export const useProducts = (filters: ProductFilters, enabled = true) =>
    useQuery({
        queryKey: productKeys.list(filters),
        queryFn: () => api<Paged<ProductRow> & { matched_barcode: boolean }>(`/products${query(filters)}`),
        placeholderData: keepPreviousData,
        enabled,
    });

export const useProduct = (id: number) =>
    useQuery({ queryKey: productKeys.detail(id), queryFn: () => api<ProductDetail>(`/products/${id}`), enabled: id > 0 });

export const useMovements = (id: number) =>
    useQuery({ queryKey: productKeys.movements(id), queryFn: () => api<Paged<Movement>>(`/products/${id}/movements`) });

export const useBatches = (status: 'expired' | 'expiring' | 'unsellable', days?: number) =>
    useQuery({
        queryKey: productKeys.batches(status, days),
        queryFn: () => api<{ data: BatchRow[] }>(`/stock/batches${query({ status, days })}`).then((r) => r.data),
    });

export const useStockSummary = () => useQuery({ queryKey: productKeys.summary, queryFn: () => api<StockSummary>('/stock/summary') });

export const useCatalogOptions = () =>
    useQuery({ queryKey: productKeys.options, queryFn: () => api<CatalogOptions>('/catalog/options'), staleTime: 60_000 });

/** Unit codes (STRIP) as words in the current language; unknown codes are shown as they are. */
/** unit('STRIP') → "strips"; unit('STRIP', 1) → "strip". Pass the count (or 1 for "per strip"). */
export function useUnitLabel(): (code: string, count?: number) => string {
    const { t } = useTranslation();
    return (code, count = 2) => (Math.abs(count) === 1 ? t(`unit_one.${code}`, { defaultValue: t(`unit.${code}`, { defaultValue: code }) }) : t(`unit.${code}`, { defaultValue: code }));
}
