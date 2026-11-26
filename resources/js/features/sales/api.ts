import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type PaymentMethod = 'cash' | 'upi' | 'card' | 'bank';
export const PAYMENT_METHODS: PaymentMethod[] = ['cash', 'upi', 'card', 'bank'];

export type PosBatch = {
    id: number;
    batch_no: string | null;
    expiry_date: string | null;
    days_to_expiry: number | null;
    qty: number;
    price_paise: number;
    mrp_paise: number;
};

export type PosProduct = {
    id: number;
    type: 'medical' | 'general';
    name: string;
    name_local: string | null;
    generic_name: string | null;
    strength: string | null;
    schedule: string | null;
    needs_prescription: boolean;
    base_unit: string;
    pricing_unit: string;
    pack_size: number;
    allow_loose_sale: boolean;
    tracks_batch: boolean;
    rack_location: string | null;
    price_paise: number;
    stock_qty: number;
    expired_qty: number;
    batches: PosBatch[];
};

export type CartLine = {
    key: string;
    product: PosProduct;
    unit: 'pack' | 'base';
    qty: number;
    batch_id: number | null;
    discount: string; // percent, as typed
};

export type CartCustomer = { id: number; name: string; mobile: string | null; balance_paise: number; credit_limit_paise: number };

export type Totals = {
    gross_paise: number;
    discount_paise: number;
    taxable_paise: number;
    cgst_paise: number;
    sgst_paise: number;
    igst_paise: number;
    cess_paise: number;
    round_off_paise: number;
    total_paise: number;
};

export type PricedLine = {
    index: number;
    product_name: string;
    batch_no: string | null;
    expiry_date: string | null;
    unit_code: string;
    qty: number;
    line_total_paise: number;
    discount_paise: number;
};

export type Preview = {
    lines: PricedLine[];
    errors: { index: number; code: string; product: string | null; available?: number; unit?: string }[];
    totals: Totals;
    doc_kind: string;
    requires: { prescription: boolean; pharmacist: boolean };
};

export type Bill = {
    id: number;
    invoice_no: string;
    doc_kind: 'tax_invoice' | 'bill_of_supply' | 'invoice_cum_bill_of_supply' | 'cash_memo';
    status: 'completed' | 'cancelled';
    cancelled: { by: string | null; at: string | null; reason: string | null } | null;
    can_cancel: boolean;
    can_return: boolean;
    returns: { id: number; return_no: string; return_date: string; total_paise: number; refund_mode: RefundMode }[];
    sale_date: string;
    completed_at: string;
    cashier: string;
    pharmacist: { name: string; reg_no: string | null } | null;
    customer: { id: number; name: string; mobile: string | null; village: string | null; balance_paise: number } | null;
    prescription: { patient_name: string; prescriber_name: string; prescriber_address: string | null } | null;
    shop: {
        name: string;
        address: string | null;
        village: string | null;
        taluka: string | null;
        district: string | null;
        pincode: string | null;
        phone: string | null;
        gst_mode: 'unregistered' | 'composition' | 'regular';
        gstin: string | null;
        drug_licences: string[];
        footer: string | null;
    };
    has_medicines: boolean;
    items: {
        line_no: number;
        product_name: string;
        hsn_code: string | null;
        schedule_code: string | null;
        batch_no: string | null;
        expiry_date: string | null;
        unit_code: string;
        qty: number;
        mrp_paise: number;
        pack_price_paise: number;
        pack_factor: number;
        unit_factor: number;
        gross_paise: number;
        discount_paise: number;
        tax_rate_percent: string;
        line_total_paise: number;
        returned_qty: number;
    }[];
    totals: Totals & { paid_paise: number; credit_paise: number };
    tax_summary: { rate_percent: string; taxable_paise: number; cgst_paise: number; sgst_paise: number; igst_paise: number; cess_paise: number }[];
    payments: { method: PaymentMethod; amount_paise: number; reference_no: string | null }[];
    refunds: { method: PaymentMethod; amount_paise: number; reference_no: string | null }[];
};

export type RefundMode = 'refund' | 'account_credit';
export type ReturnBucket = 'sellable' | 'unsellable';

/** What the customer gets back for the chosen lines, before anything is saved. */
export type ReturnPreview = {
    lines: { line_no: number; qty: number; unit_code: string; line_total_paise: number }[];
    totals: Totals;
};

/** A saved return (credit note). */
export type CreditNote = {
    id: number;
    return_no: string;
    return_date: string;
    created_at: string;
    sale: { id: number; invoice_no: string; sale_date: string };
    customer: { id: number; name: string; balance_paise: number } | null;
    reason: string;
    refund_mode: RefundMode;
    refund_method: PaymentMethod | null;
    by: string;
    items: { line_no: number; product_name: string; batch_no: string | null; unit_code: string; qty: number; bucket: ReturnBucket; line_total_paise: number; tax_rate_percent: string }[];
    totals: Totals;
};

export type SaleRow = {
    id: number;
    invoice_no: string;
    completed_at: string;
    buyer_name: string | null;
    total_paise: number;
    paid_paise: number;
    credit_paise: number;
    methods: PaymentMethod[];
    status: string;
};

export type Customer = {
    id: number;
    name: string;
    mobile: string | null;
    village: string | null;
    address: string | null;
    notes: string | null;
    credit_limit_paise: number;
    balance_paise: number;
    last_payment_at: string | null;
    /** Days since the last payment (or since the customer was added when nothing was paid yet). */
    days_since_payment: number;
    /** Owing and nothing paid for longer than the shop's limit. */
    overdue: boolean;
};

export type LedgerEntry = {
    id: number;
    date: string;
    type: string;
    debit_paise: number;
    credit_paise: number;
    balance_after_paise: number;
    note: string | null;
    sale_id: number | null;
    /** May be undone from the statement (a payment on account, an opening, an adjustment not undone yet). */
    reversible?: boolean;
};

type Paged<T> = { data: T[]; meta: { total: number; page: number; last_page: number } };

/** What the server needs to price or save the cart. */
export function cartPayload(lines: CartLine[]) {
    return lines.map((line) => ({
        product_id: line.product.id,
        unit: line.unit,
        qty: line.qty,
        batch_id: line.batch_id,
        discount_percent: line.discount.trim() === '' ? 0 : Number(line.discount),
    }));
}

export const saleKeys = {
    all: ['sales'] as const,
    customers: ['customers'] as const,
};

export const searchProducts = (q: string) =>
    api<{ data: PosProduct[]; matched_barcode: boolean; barcode_unit: 'pack' | 'base' }>(`/pos/search?q=${encodeURIComponent(q)}`);

export const useQuickItems = () =>
    useQuery({ queryKey: ['pos', 'quick'], queryFn: () => api<{ data: PosProduct[] }>('/pos/search?quick=1').then((r) => r.data), staleTime: 60_000 });

export const usePreview = (lines: CartLine[]) => {
    const payload = cartPayload(lines);
    return useQuery({
        queryKey: ['pos', 'preview', payload],
        queryFn: () => api<Preview>('/sales/preview', { method: 'POST', body: { lines: payload } }),
        enabled: lines.length > 0,
        placeholderData: keepPreviousData,
    });
};

export const usePharmacists = (enabled: boolean) =>
    useQuery({ queryKey: ['pos', 'pharmacists'], queryFn: () => api<{ data: { id: number; name: string }[] }>('/pos/pharmacists').then((r) => r.data), enabled });

export const useCreditNote = (id: number) => useQuery({ queryKey: ['sales', 'return', id], queryFn: () => api<CreditNote>(`/returns/${id}`) });

export const useBill = (id: number) => useQuery({ queryKey: ['sales', 'bill', id], queryFn: () => api<Bill>(`/sales/${id}`) });

export const useSales = (search: string, from: string, page: number) =>
    useQuery({
        queryKey: ['sales', 'list', search, from, page],
        queryFn: () => {
            const params = new URLSearchParams({ page: String(page) });
            if (search) params.set('search', search);
            if (from) {
                params.set('from', from);
                params.set('to', from);
            }
            return api<Paged<SaleRow>>(`/sales?${params.toString()}`);
        },
        placeholderData: keepPreviousData,
    });

export const useCustomers = (search: string, owing: boolean) =>
    useQuery({
        queryKey: ['customers', 'list', search, owing],
        queryFn: () =>
            api<Paged<Customer> & { total_owed_paise: number; customers_owing: number }>(
                `/customers?search=${encodeURIComponent(search)}${owing ? '&owing=1' : ''}`,
            ),
        placeholderData: keepPreviousData,
    });

export type Doctor = { id: number; name: string; reg_no: string | null; clinic: string | null; address: string | null; phone: string | null; is_active: boolean };

/** Doctors matching what is typed on the prescription step. */
export const useDoctors = (search: string, enabled: boolean) =>
    useQuery({
        queryKey: ['doctors', search],
        queryFn: () => api<{ data: Doctor[] }>(`/doctors?search=${encodeURIComponent(search)}`).then((r) => r.data),
        enabled: enabled && search.trim().length >= 2,
        placeholderData: keepPreviousData,
    });

export const useCustomer = (id: number) =>
    useQuery({ queryKey: ['customers', 'detail', id], queryFn: () => api<Customer & { entries: LedgerEntry[] }>(`/customers/${id}`) });
