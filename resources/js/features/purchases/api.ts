import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaymentMethod } from '@/features/sales/api';

export type SupplierKind = 'distributor' | 'wholesaler' | 'manufacturer' | 'other';
export const SUPPLIER_KINDS: SupplierKind[] = ['distributor', 'wholesaler', 'manufacturer', 'other'];
export type ContactRole = 'mr' | 'sales' | 'delivery' | 'accounts' | 'owner' | 'other';
export const CONTACT_ROLES: ContactRole[] = ['mr', 'sales', 'delivery', 'accounts', 'owner', 'other'];

export type Supplier = {
    id: number;
    name: string;
    kind: SupplierKind;
    contact_person: string | null;
    mobile: string | null;
    address: string | null;
    gstin: string | null;
    drug_licence_no: string | null;
    payment_terms_days: number;
    notes: string | null;
    balance_paise: number;
    last_payment_at: string | null;
};

export type SupplierContact = {
    id: number;
    name: string;
    role: ContactRole;
    company: string | null;
    mobile: string | null;
    visit_day: number | null;
    schemes: string | null;
    notes: string | null;
};

export type SupplierEntry = {
    id: number;
    date: string;
    type: string;
    credit_paise: number;
    debit_paise: number;
    balance_after_paise: number;
    note: string | null;
    purchase_id: number | null;
    return_id: number | null;
};

export type PurchaseStatus = 'draft' | 'confirmed' | 'cancelled';

export type PurchaseRow = {
    id: number;
    purchase_no: string | null;
    status: PurchaseStatus;
    supplier: { id: number; name: string };
    supplier_invoice_no: string | null;
    invoice_date: string | null;
    received_date: string;
    total_paise: number;
    paid_paise: number;
    due_date: string | null;
    lines: number;
};

export type SupplierDetail = Supplier & {
    contacts: SupplierContact[];
    entries: SupplierEntry[];
    purchases: Omit<PurchaseRow, 'supplier' | 'invoice_date' | 'lines'>[];
};

export type PurchaseTotals = {
    gross_paise: number;
    discount_paise: number;
    taxable_paise: number;
    cgst_paise: number;
    sgst_paise: number;
    igst_paise: number;
    cess_paise: number;
    other_charges_paise: number;
    round_off_paise: number;
    total_paise: number;
};

export type PurchaseItem = {
    line_no: number;
    product_id: number;
    product_name: string;
    hsn_code: string | null;
    unit_code: string;
    unit_factor: number;
    qty: number;
    free_qty: number;
    qty_base: number;
    rate_paise: number;
    discount_percent: string;
    discount_paise: number;
    tax_rate_percent: string;
    taxable_paise: number;
    tax_paise: number;
    line_total_paise: number;
    batch_no: string | null;
    expiry_date: string | null;
    mfg_date: string | null;
    mrp_paise: number;
    selling_price_paise: number | null;
    cost_rate: string;
    batch_id: number | null;
};

export type Purchase = PurchaseRow & {
    supplier: { id: number; name: string; gstin: string | null; balance_paise: number; payment_terms_days: number };
    inter_state: boolean;
    other_charges_paise: number;
    invoice_total_paise: number | null;
    notes: string | null;
    by: string;
    confirmed_at: string | null;
    cancelled: { at: string; reason: string } | null;
    totals: PurchaseTotals;
    items: PurchaseItem[];
    payments: { direction: 'in' | 'out'; method: PaymentMethod; amount_paise: number; reference_no: string | null; at: string }[];
};

/** A line as typed on the screen; the server prices it. */
export type DraftLine = {
    key: string;
    product_id: number;
    product_name: string;
    tracks_batch: boolean;
    tracks_expiry: boolean;
    pack_size: number;
    base_unit: string;
    pricing_unit: string;
    unit: 'pack' | 'base';
    qty: string;
    free_qty: string;
    rate: string; // rupees as typed, per unit before tax
    discount: string; // percent as typed
    batch_no: string;
    expiry_month: string;
    mrp: string; // rupees as typed, per pricing unit
};

export type PricedLine = {
    index: number;
    product_name: string;
    unit_code: string;
    qty: number;
    free_qty: number;
    qty_base: number;
    gross_paise: number;
    discount_paise: number;
    tax_rate_percent: string;
    taxable_paise: number;
    cgst_paise: number;
    sgst_paise: number;
    igst_paise: number;
    cess_paise: number;
    line_total_paise: number;
    cost_per_pack_paise: number;
    pack_size: number;
    pricing_unit: string;
    mrp_paise: number;
};

export type PurchasePreview = {
    lines: PricedLine[];
    errors: { index: number; code: string; product: string | null }[];
    totals: PurchaseTotals;
    inter_state: boolean;
};

export type Lot = {
    id: number;
    product_id: number;
    product_name: string;
    batch_no: string | null;
    expiry_date: string | null;
    expired: boolean;
    qty_sellable: number;
    qty_unsellable: number;
    cost_rate: string;
    supplier_id: number | null;
    base_unit: string;
    pack_size: number;
    pricing_unit: string;
};

export type DebitNote = {
    id: number;
    return_no: string;
    return_date: string;
    created_at: string;
    supplier: { id: number; name: string; balance_paise: number };
    reason: string;
    settlement: 'reduce_balance' | 'refund_received';
    refund_method: PaymentMethod | null;
    by: string;
    items: { line_no: number; product_name: string; batch_no: string | null; bucket: 'sellable' | 'unsellable'; qty_base: number; cost_rate: string; tax_rate_percent: string; taxable_paise: number; line_total_paise: number }[];
    totals: { taxable_paise: number; cgst_paise: number; sgst_paise: number; igst_paise: number; cess_paise: number; round_off_paise: number; total_paise: number };
};

type Paged<T> = { data: T[]; meta: { total: number; page: number; last_page: number } };

export const purchaseKeys = {
    suppliers: ['suppliers'] as const,
    purchases: ['purchases'] as const,
};

/** What the server needs to price or save the lines. */
export function linePayload(lines: DraftLine[]) {
    const paise = (rupees: string) => (rupees.trim() === '' ? null : Math.round(Number(rupees) * 100));
    return lines.map((line) => ({
        product_id: line.product_id,
        unit: line.unit,
        qty: /^\d+$/.test(line.qty) ? Number(line.qty) : 0,
        free_qty: /^\d+$/.test(line.free_qty) ? Number(line.free_qty) : 0,
        rate_paise: paise(line.rate) ?? 0,
        discount_percent: line.discount.trim() === '' ? 0 : Number(line.discount),
        batch_no: line.batch_no.trim() || null,
        expiry_month: line.expiry_month || null,
        mrp_paise: paise(line.mrp),
    }));
}

export const useSuppliers = (search: string, owing: boolean, enabled = true) =>
    useQuery({
        queryKey: ['suppliers', 'list', search, owing],
        queryFn: () => api<Paged<Supplier> & { total_owed_paise: number; suppliers_owed: number }>(`/suppliers?search=${encodeURIComponent(search)}${owing ? '&owing=1' : ''}`),
        placeholderData: keepPreviousData,
        enabled,
    });

export const useSupplier = (id: number) => useQuery({ queryKey: ['suppliers', 'detail', id], queryFn: () => api<SupplierDetail>(`/suppliers/${id}`) });

export const useVisits = () =>
    useQuery({ queryKey: ['suppliers', 'visits'], queryFn: () => api<{ data: (SupplierContact & { supplier: { id: number; name: string } })[] }>('/suppliers/visits').then((r) => r.data) });

export const usePurchases = (status: string, search: string, page: number) =>
    useQuery({
        queryKey: ['purchases', 'list', status, search, page],
        queryFn: () => {
            const params = new URLSearchParams({ page: String(page) });
            if (status) params.set('status', status);
            if (search) params.set('search', search);
            return api<Paged<PurchaseRow>>(`/purchases?${params.toString()}`);
        },
        placeholderData: keepPreviousData,
    });

export const usePurchase = (id: number, enabled = true) => useQuery({ queryKey: ['purchases', 'detail', id], queryFn: () => api<Purchase>(`/purchases/${id}`), enabled });

export const usePurchasePreview = (supplierId: number | null, lines: DraftLine[], otherChargesPaise: number) => {
    const payload = linePayload(lines);
    return useQuery({
        queryKey: ['purchases', 'preview', supplierId, payload, otherChargesPaise],
        queryFn: () => api<PurchasePreview>('/purchases/preview', { method: 'POST', body: { supplier_id: supplierId, other_charges_paise: otherChargesPaise, lines: payload } }),
        enabled: lines.length > 0,
        placeholderData: keepPreviousData,
    });
};

export const useLots = (search: string, supplierId: number | null, expiredOnly: boolean) =>
    useQuery({
        queryKey: ['purchases', 'lots', search, supplierId, expiredOnly],
        queryFn: () => {
            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (supplierId) params.set('supplier_id', String(supplierId));
            if (expiredOnly) params.set('expired', '1');
            return api<{ data: Lot[] }>(`/purchase-returns/lots?${params.toString()}`).then((r) => r.data);
        },
        placeholderData: keepPreviousData,
    });

export const useDebitNote = (id: number) => useQuery({ queryKey: ['purchases', 'return', id], queryFn: () => api<DebitNote>(`/purchase-returns/${id}`) });
