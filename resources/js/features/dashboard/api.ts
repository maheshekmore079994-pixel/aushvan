import { useQuery } from '@tanstack/react-query';
import type { ProductType, StockStatus } from '@/features/products/api';
import { api } from '@/lib/api';

export type SalesTotals = { bills: number; sales_paise: number; udhar_paise: number; profit_paise?: number };

/** Blocks the signed-in user may not see are simply absent. */
export type Dashboard = {
    today: string;
    mine: SalesTotals;
    udhar: { total_paise: number; customers: number; top: { id: number; name: string; village: string | null; balance_paise: number }[] };
    /** Only for users who enter purchases. */
    supplier_dues?: { total_paise: number; suppliers: number; overdue_paise: number; drafts: number };
    /** Only for users who close the day or enter expenses. */
    day?: { id: number; status: 'open' | 'closed'; business_date: string } | null;
    expenses_today?: { total_paise: number };
    /** Only for settings managers, and only within 60 days of the licence retention date (negative days = past). */
    licence?: { due: string; days: number };
    low_stock: {
        id: number;
        name: string;
        type: ProductType;
        stock_qty: number;
        min_stock_qty: number;
        base_unit: string;
        pricing_unit: string;
        pack_size: number;
        stock_status: StockStatus;
    }[];
    expiring: { product_id: number; name: string; batch_no: string | null; expiry_date: string; days_left: number }[];
    recent_bills?: { id: number; invoice_no: string; customer: string | null; total_paise: number; credit_paise: number; at: string }[];
    sales?: { today: SalesTotals; yesterday: SalesTotals; month: SalesTotals; last_month_to_date: SalesTotals };
    received_today?: { cash: number; upi: number; card: number; bank: number };
    trend?: { date: string; bills: number; sales_paise: number }[];
    split_30?: { medical_paise: number; general_paise: number };
    top_products?: { id: number; name: string; type: ProductType; amount_paise: number }[];
    /** Only for users with backup.manage. */
    backup?: { last_verified_at: string | null; last_in_folder_at: string | null; due: boolean; auto_wanted: boolean; folder: string | null; folder_ok: boolean };
};

export const dashboardKey = ['dashboard'] as const;

export const useDashboard = () => useQuery({ queryKey: dashboardKey, queryFn: () => api<Dashboard>('/dashboard'), staleTime: 0, refetchOnMount: 'always' });
