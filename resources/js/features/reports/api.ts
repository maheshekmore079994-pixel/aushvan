import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type ColumnType = 'text' | 'date' | 'datetime' | 'money' | 'int' | 'percent' | 'label';
export type Column = { key: string; type: ColumnType; prefix?: string };
export type ReportFilters = { from?: string; to?: string; type?: 'medical' | 'general' | ''; days?: number };
export type ReportResult = {
    key: string;
    columns: Column[];
    rows: Record<string, string | number | null>[];
    totals: Record<string, number>;
    filters: ReportFilters;
    truncated: boolean;
};

export type ReportGroup = 'sales' | 'stock' | 'udhar' | 'purchases' | 'cash';
type FilterName = 'period' | 'type' | 'days';

/** Mirrors ReportRunner::REPORTS on the server; a test keeps the two lists the same. */
export const REPORTS: Record<string, { group: ReportGroup; filters: FilterName[] }> = {
    sales_by_day: { group: 'sales', filters: ['period'] },
    sales_by_item: { group: 'sales', filters: ['period', 'type'] },
    bills: { group: 'sales', filters: ['period'] },
    gst_summary: { group: 'sales', filters: ['period'] },
    returns: { group: 'sales', filters: ['period'] },
    stock_summary: { group: 'stock', filters: ['type'] },
    reorder: { group: 'stock', filters: ['type'] },
    expiry: { group: 'stock', filters: ['days', 'type'] },
    slow_moving: { group: 'stock', filters: ['days', 'type'] },
    stock_movements: { group: 'stock', filters: ['period', 'type'] },
    udhar_outstanding: { group: 'udhar', filters: [] },
    purchases: { group: 'purchases', filters: ['period'] },
    purchases_by_item: { group: 'purchases', filters: ['period', 'type'] },
    supplier_dues: { group: 'purchases', filters: [] },
    expenses: { group: 'cash', filters: ['period'] },
    day_closings: { group: 'cash', filters: ['period'] },
};

export function reportQuery(filters: ReportFilters): string {
    const query = new URLSearchParams();
    for (const [name, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '') query.set(name, String(value));
    }
    return query.toString();
}

export const useReport = (key: string, filters: ReportFilters) =>
    useQuery({
        queryKey: ['reports', key, filters],
        queryFn: () => api<ReportResult>(`/reports/${key}?${reportQuery(filters)}`),
        placeholderData: keepPreviousData,
    });

export function exportUrl(key: string, filters: ReportFilters, format: 'csv' | 'xlsx' | 'pdf', language: string): string {
    return `/api/v1/reports/${key}/export?${reportQuery(filters)}&format=${format}&lang=${language}`;
}
