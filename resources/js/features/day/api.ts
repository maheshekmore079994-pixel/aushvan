import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { PaymentMethod } from '@/features/sales/api';

export type MovementType = 'cash_in' | 'bank_deposit' | 'owner_withdrawal' | 'other_out';
export const MOVEMENT_TYPES: MovementType[] = ['cash_in', 'bank_deposit', 'owner_withdrawal', 'other_out'];

export type DayFigures = {
    window_from: string;
    window_to: string | null;
    opening_cash_paise: number;
    cash: {
        cash_sales: number;
        udhar_received: number;
        supplier_refunds: number;
        other_in: number;
        refunds: number;
        expenses: number;
        paid_to_suppliers: number;
        other_out: number;
        cash_in_paise: number;
        cash_out_paise: number;
        in_paise: number;
        out_paise: number;
    };
    expected_cash_paise: number;
    non_cash: { upi: number; card: number; bank: number };
    bills: { count: number; total_paise: number; udhar_paise: number };
    movements: { id: number; type: MovementType; amount_paise: number; note: string | null; at: string }[];
};

export type CashDay = {
    id: number;
    business_date: string;
    status: 'open' | 'closed';
    opened_by: string | null;
    opened_at: string;
    opening_cash_paise: number;
    closed_by: string | null;
    closed_at: string | null;
    expected_cash_paise: number | null;
    counted_cash_paise: number | null;
    difference_paise: number | null;
    note: string | null;
    reopened: { at: string; reason: string } | null;
    figures?: DayFigures;
};

export type DayState = { open: CashDay | null; last_closed: CashDay | null; suggested_opening_paise: number; today: string };

export type ExpenseCategory = { id: number; name: string; name_local: string | null; is_active: boolean };

export type Expense = {
    id: number;
    date: string;
    category: { id: number; name: string; name_local: string | null };
    amount_paise: number;
    method: PaymentMethod;
    description: string | null;
    reference_no: string | null;
    by: string;
    cancelled: { at: string; reason: string } | null;
};

export type ExpenseList = {
    data: Expense[];
    meta: { total: number; page: number; last_page: number };
    from: string;
    to: string;
    total_paise: number;
    by_category: { id: number; name: string; name_local: string | null; amount_paise: number }[];
};

export const dayKeys = { all: ['day'] as const, expenses: ['expenses'] as const };

export const useDay = () => useQuery({ queryKey: ['day', 'state'], queryFn: () => api<DayState>('/day'), refetchInterval: 60_000 });

export const useDayHistory = (page: number) =>
    useQuery({
        queryKey: ['day', 'history', page],
        queryFn: () => api<{ data: CashDay[]; meta: { total: number; page: number; last_page: number } }>(`/day/history?page=${page}`),
        placeholderData: keepPreviousData,
    });

export const useDaySession = (id: number, enabled = true) => useQuery({ queryKey: ['day', 'session', id], queryFn: () => api<CashDay>(`/day/${id}`), enabled });

export const useExpenses = (from: string, to: string, categoryId: number | null, page: number) =>
    useQuery({
        queryKey: ['expenses', 'list', from, to, categoryId, page],
        queryFn: () => {
            const params = new URLSearchParams({ page: String(page) });
            if (from) params.set('from', from);
            if (to) params.set('to', to);
            if (categoryId) params.set('category_id', String(categoryId));
            return api<ExpenseList>(`/expenses?${params.toString()}`);
        },
        placeholderData: keepPreviousData,
    });

export const useExpenseCategories = (all = false) =>
    useQuery({ queryKey: ['expenses', 'categories', all], queryFn: () => api<{ data: ExpenseCategory[] }>(`/expense-categories${all ? '?all=1' : ''}`).then((r) => r.data) });
