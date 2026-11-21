import {
    ArrowDownUp,
    ArrowLeft,
    BadgeIndianRupee,
    BarChart3,
    CalendarClock,
    CalendarDays,
    FileSpreadsheet,
    FileText,
    HandCoins,
    History,
    PackageSearch,
    Percent,
    Printer,
    ReceiptText,
    ShoppingBag,
    Snail,
    Table2,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useParams } from 'react-router-dom';
import { Alert, Button, Card, EmptyState, IconTile, PageHeader, Spinner, type TileTone } from '@/components/ui';
import { useShop } from '@/features/settings/api';
import { dateLocale } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { exportUrl, REPORTS, useReport, type Column, type ReportFilters, type ReportGroup } from './api';

const ICONS: Record<string, LucideIcon> = {
    sales_by_day: CalendarDays,
    sales_by_item: ShoppingBag,
    bills: ReceiptText,
    gst_summary: Percent,
    stock_summary: PackageSearch,
    reorder: TriangleAlert,
    expiry: CalendarClock,
    slow_moving: Snail,
    stock_movements: History,
    udhar_outstanding: HandCoins,
};

const GROUPS: { key: ReportGroup; tone: TileTone; icon: LucideIcon }[] = [
    { key: 'sales', tone: 'success', icon: BadgeIndianRupee },
    { key: 'stock', tone: 'primary', icon: PackageSearch },
    { key: 'udhar', tone: 'warning', icon: HandCoins },
];

/** The list of reports, grouped the way the owner thinks: sales, stock, udhar. */
export function ReportsPage() {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-6">
            <PageHeader title={t('nav.reports')} subtitle={t('reports.intro')} />
            {GROUPS.map((group) => (
                <section key={group.key} aria-labelledby={`group-${group.key}`}>
                    <h2 id={`group-${group.key}`} className="mb-3 flex items-center gap-2 text-lg font-semibold">
                        <group.icon className="size-5 text-ink-soft" aria-hidden />
                        {t(`reports.group.${group.key}`)}
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {Object.entries(REPORTS)
                            .filter(([, definition]) => definition.group === group.key)
                            .map(([key]) => {
                                const Icon = ICONS[key] ?? BarChart3;
                                return (
                                    <Link
                                        key={key}
                                        to={`/reports/${key}`}
                                        className="flex items-start gap-3 rounded-xl border border-line/70 bg-white p-4 shadow-card transition hover:-translate-y-0.5 hover:border-primary/40"
                                    >
                                        <IconTile tone={group.tone}>
                                            <Icon className="size-6" />
                                        </IconTile>
                                        <span className="min-w-0">
                                            <span className="block font-semibold">{t(`reports.name.${key}`)}</span>
                                            <span className="block text-sm text-ink-soft">{t(`reports.about.${key}`)}</span>
                                        </span>
                                    </Link>
                                );
                            })}
                    </div>
                </section>
            ))}
        </div>
    );
}

const iso = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** Ready-made periods, so nobody has to pick two dates for the usual questions. */
function presets(): Record<string, { from: string; to: string }> {
    const today = new Date();
    const shift = (days: number) => new Date(today.getFullYear(), today.getMonth(), today.getDate() + days);
    return {
        today: { from: iso(today), to: iso(today) },
        yesterday: { from: iso(shift(-1)), to: iso(shift(-1)) },
        last_7: { from: iso(shift(-6)), to: iso(today) },
        this_month: { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) },
        last_month: { from: iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)), to: iso(new Date(today.getFullYear(), today.getMonth(), 0)) },
    };
}

function useCell() {
    const { t, i18n } = useTranslation();
    const locale = dateLocale(i18n.language);

    return (column: Column, value: string | number | null | undefined): string => {
        if (value === null || value === undefined || value === '') return column.type === 'money' || column.type === 'int' ? '0' : '—';
        switch (column.type) {
            case 'money':
                return formatMoney(Number(value));
            case 'int':
                return Number(value).toLocaleString('en-IN');
            case 'percent':
                return `${Number(value)}%`;
            case 'date': {
                const [year, month, day] = String(value).split('-').map(Number);
                return new Date(year, month - 1, day).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' });
            }
            case 'datetime':
                return new Date(String(value)).toLocaleString(locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
            case 'label':
                return t(`${column.prefix}.${value}`, { defaultValue: String(value) });
            default:
                return String(value);
        }
    };
}

const isNumber = (column: Column) => column.type === 'money' || column.type === 'int' || column.type === 'percent';

export function ReportPage() {
    const { t, i18n } = useTranslation();
    const { key = '' } = useParams();
    const definition = REPORTS[key];
    const shop = useShop();
    const ranges = useMemo(presets, []);
    const [preset, setPreset] = useState('this_month');
    const [period, setPeriod] = useState(ranges.this_month);
    const [type, setType] = useState<'' | 'medical' | 'general'>('');
    const [days, setDays] = useState(key === 'expiry' ? 90 : 60);
    const [sort, setSort] = useState<{ key: string; down: boolean } | null>(null);
    const cell = useCell();

    const filters: ReportFilters = {};
    if (definition?.filters.includes('period')) Object.assign(filters, period);
    if (definition?.filters.includes('type')) filters.type = type;
    if (definition?.filters.includes('days')) filters.days = days;

    const report = useReport(definition ? key : 'sales_by_day', filters);

    const rows = useMemo(() => {
        const data = report.data?.rows ?? [];
        if (!sort) return data;
        const sorted = [...data].sort((a, b) => {
            const [x, y] = [a[sort.key], b[sort.key]];
            if (typeof x === 'number' && typeof y === 'number') return x - y;
            return String(x ?? '').localeCompare(String(y ?? ''), undefined, { numeric: true });
        });
        return sort.down ? sorted.reverse() : sorted;
    }, [report.data, sort]);

    if (!definition) return <Navigate to="/reports" replace />;

    const chip = (active: boolean) =>
        `min-h-8 rounded-full border px-3 text-sm font-medium transition-colors ${active ? 'border-primary bg-primary text-white' : 'border-line bg-white text-ink hover:border-primary/40'}`;
    const dateInput = 'min-h-10 rounded-lg border border-line bg-white px-3';
    const data = report.data;
    const hasTotals = data && Object.keys(data.totals).length > 0;

    return (
        <div className="flex flex-col gap-4">
            <Link to="/reports" className="inline-flex min-h-9 items-center gap-1 self-start rounded-md pr-2 font-semibold text-primary hover:bg-primary-soft print:hidden">
                <ArrowLeft className="size-5" aria-hidden />
                {t('nav.reports')}
            </Link>
            <PageHeader title={t(`reports.name.${key}`)} subtitle={t(`reports.about.${key}`)}>
                <a className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-line bg-white px-3.5 text-sm font-semibold shadow-xs hover:bg-primary-soft/40" href={exportUrl(key, filters, 'xlsx', i18n.language)} download>
                    <FileSpreadsheet className="size-5 text-success" aria-hidden />
                    {t('reports.excel')}
                </a>
                <a className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-line bg-white px-3.5 text-sm font-semibold shadow-xs hover:bg-primary-soft/40" href={exportUrl(key, filters, 'pdf', i18n.language)} download>
                    <FileText className="size-5 text-danger" aria-hidden />
                    {t('reports.pdf')}
                </a>
                <a className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-line bg-white px-3.5 text-sm font-semibold shadow-xs hover:bg-primary-soft/40" href={exportUrl(key, filters, 'csv', i18n.language)} download>
                    <Table2 className="size-5 text-primary" aria-hidden />
                    {t('reports.csv')}
                </a>
                <Button variant="secondary" onClick={() => window.print()} icon={<Printer className="size-5" aria-hidden />}>
                    {t('common.print')}
                </Button>
            </PageHeader>

            {definition.filters.length > 0 && (
                <Card className="flex flex-col gap-3 print:hidden">
                    {definition.filters.includes('period') && (
                        <div className="flex flex-wrap items-center gap-2">
                            {Object.keys(ranges).map((name) => (
                                <button
                                    key={name}
                                    type="button"
                                    className={chip(preset === name)}
                                    aria-pressed={preset === name}
                                    onClick={() => {
                                        setPreset(name);
                                        setPeriod(ranges[name]);
                                    }}
                                >
                                    {t(`reports.preset.${name}`)}
                                </button>
                            ))}
                            <label className="flex items-center gap-2 text-sm font-medium">
                                {t('reports.from')}
                                <input
                                    type="date"
                                    className={dateInput}
                                    value={period.from}
                                    max={period.to}
                                    onChange={(e) => {
                                        if (!e.target.value) return;
                                        setPreset('custom');
                                        setPeriod({ ...period, from: e.target.value });
                                    }}
                                />
                            </label>
                            <label className="flex items-center gap-2 text-sm font-medium">
                                {t('reports.to')}
                                <input
                                    type="date"
                                    className={dateInput}
                                    value={period.to}
                                    min={period.from}
                                    onChange={(e) => {
                                        if (!e.target.value) return;
                                        setPreset('custom');
                                        setPeriod({ ...period, to: e.target.value });
                                    }}
                                />
                            </label>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        {definition.filters.includes('type') &&
                            (['', 'medical', 'general'] as const).map((value) => (
                                <button key={value || 'all'} type="button" className={chip(type === value)} aria-pressed={type === value} onClick={() => setType(value)}>
                                    {value ? t(`products.type.${value}`) : t('products.all_types')}
                                </button>
                            ))}
                        {definition.filters.includes('days') &&
                            (key === 'expiry' ? [30, 60, 90, 180] : [30, 60, 90, 180]).map((value) => (
                                <button key={value} type="button" className={chip(days === value)} aria-pressed={days === value} onClick={() => setDays(value)}>
                                    {t('reports.days_n', { count: value })}
                                </button>
                            ))}
                    </div>
                </Card>
            )}

            {/* Heading that appears only on paper. */}
            <div className="hidden print:block">
                <p className="text-lg font-bold">{shop.data?.name}</p>
                <p className="font-semibold">{t(`reports.name.${key}`)}</p>
                {filters.from && filters.to && <p>{t('reports.period', { from: cell({ key: 'from', type: 'date' }, filters.from), to: cell({ key: 'to', type: 'date' }, filters.to) })}</p>}
            </div>

            {report.isError && <Alert>{t('app.error_generic')}</Alert>}
            {report.isPending && <Spinner label={t('common.loading')} />}
            {data?.truncated && <Alert tone="warning">{t('reports.truncated', { count: data.rows.length })}</Alert>}

            {data && data.rows.length === 0 && <EmptyState icon={<BarChart3 className="size-8" />} title={t('reports.empty')} />}

            {data && data.rows.length > 0 && (
                <>
                    {hasTotals && (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6 print:hidden">
                            {data.columns
                                .filter((column) => data.totals[column.key] !== undefined)
                                .map((column) => (
                                    <div key={column.key} className="rounded-xl border border-line/70 bg-white px-3 py-2 shadow-card">
                                        <p className="text-sm font-medium text-ink-soft">{t(`reports.col.${column.key}`)}</p>
                                        <p className="text-xl font-bold">{cell(column, data.totals[column.key])}</p>
                                    </div>
                                ))}
                        </div>
                    )}
                    <Card className="overflow-x-auto p-0 sm:p-0 print:border-0 print:shadow-none">
                        <table className="w-full text-left">
                            <thead className="border-b border-line text-ink-soft">
                                <tr>
                                    {data.columns.map((column) => (
                                        <th key={column.key} className={`px-3 py-2 font-semibold ${isNumber(column) ? 'text-right' : ''}`} aria-sort={sort?.key === column.key ? (sort.down ? 'descending' : 'ascending') : undefined}>
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1 uppercase hover:text-ink"
                                                onClick={() => setSort({ key: column.key, down: sort?.key === column.key ? !sort.down : isNumber(column) })}
                                            >
                                                {t(`reports.col.${column.key}`)}
                                                <ArrowDownUp className={`size-3.5 print:hidden ${sort?.key === column.key ? 'text-primary' : 'opacity-40'}`} aria-hidden />
                                            </button>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {rows.map((row, index) => (
                                    <tr key={index}>
                                        {data.columns.map((column) => {
                                            const value = row[column.key];
                                            const negative = column.type !== 'text' && typeof value === 'number' && value < 0;
                                            return (
                                                <td key={column.key} className={`px-3 py-2 ${isNumber(column) ? 'whitespace-nowrap text-right' : ''} ${negative ? 'font-semibold text-danger-dark' : ''}`}>
                                                    {cell(column, value)}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                            {hasTotals && (
                                <tfoot className="border-t-2 border-ink/20 font-bold">
                                    <tr>
                                        {data.columns.map((column, index) => (
                                            <td key={column.key} className={`px-3 py-2 ${isNumber(column) ? 'whitespace-nowrap text-right' : ''}`}>
                                                {data.totals[column.key] !== undefined ? cell(column, data.totals[column.key]) : index === 0 ? t('reports.total') : ''}
                                            </td>
                                        ))}
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </Card>
                    <p className="text-center text-sm text-ink-soft print:hidden">{t('reports.rows', { count: data.rows.length })}</p>
                </>
            )}
        </div>
    );
}
