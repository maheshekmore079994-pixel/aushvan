import {
    ArrowDownRight,
    ArrowUpRight,
    BarChart3,
    Boxes,
    CalendarCheck,
    CalendarClock,
    CheckCircle2,
    Coins,
    ChevronRight,
    Circle,
    HandCoins,
    PackagePlus,
    ReceiptText,
    ScanBarcode,
    ShoppingCart,
    TrendingUp,
    Truck,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { Mascot } from '@/components/Mascot';
import { useToast } from '@/components/toast';
import { Alert, Badge, Card, IconTile, Spinner, type TileTone } from '@/components/ui';
import { useSession } from '@/features/auth/session';
import { useUnitLabel } from '@/features/products/api';
import { ExpiryBadge, StockBadge, TypeBadge } from '@/features/products/badges';
import { useShop, useUsers, type BackupRow } from '@/features/settings/api';
import { api } from '@/lib/api';
import { dateLocale } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import { dashboardKey, useDashboard, type Dashboard, type SalesTotals } from './api';
import { ColumnChart, RankList, SplitBar } from './charts';
import { StockOverview } from './StockOverview';

function greetingKey(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'dashboard.greeting_morning';
    if (hour < 17) return 'dashboard.greeting_afternoon';
    return 'dashboard.greeting_evening';
}

/** "2026-11-11" as a local calendar day (never shifted by the time zone). */
function localDay(iso: string): Date {
    const [year, month, day] = iso.split('-').map(Number);
    return new Date(year, month - 1, day);
}

const QUICK: { to: string; label: string; icon: LucideIcon; tone: TileTone; permission?: string }[] = [
    { to: '/sale', label: 'nav.new_sale', icon: ShoppingCart, tone: 'success', permission: 'sales.create' },
    { to: '/products/new', label: 'products.add', icon: PackagePlus, tone: 'primary', permission: 'products.manage' },
    { to: '/udhar', label: 'udhar.receive', icon: HandCoins, tone: 'warning', permission: 'credit.receive' },
    { to: '/day', label: 'dashboard.day_button', icon: CalendarCheck, tone: 'medical', permission: 'day.close' },
    { to: '/products', label: 'nav.products', icon: Boxes, tone: 'general' },
    { to: '/expiry', label: 'nav.expiry', icon: CalendarClock, tone: 'danger' },
    { to: '/labels', label: 'nav.labels', icon: ScanBarcode, tone: 'medical', permission: 'products.manage' },
    { to: '/reports', label: 'nav.reports', icon: BarChart3, tone: 'primary', permission: 'reports.view' },
    { to: '/sales', label: 'nav.sales', icon: ReceiptText, tone: 'neutral', permission: 'sales.create' },
];

/** Change against an earlier period, in words and with an arrow; nothing when there is no earlier figure. */
function Change({ now, before, label }: { now: number; before: number; label: string }) {
    const { t } = useTranslation();
    if (before <= 0) return null;

    const percent = Math.round(((now - before) / before) * 100);
    const up = percent >= 0;
    return (
        <span className="flex flex-wrap items-center gap-1 text-sm text-ink-soft">
            <span className={`inline-flex items-center font-semibold ${up ? 'text-success-dark' : 'text-danger-dark'}`}>
                {up ? <ArrowUpRight className="size-4" aria-hidden /> : <ArrowDownRight className="size-4" aria-hidden />}
                {t(up ? 'dashboard.up_percent' : 'dashboard.down_percent', { percent: Math.abs(percent) })}
            </span>
            {label}
        </span>
    );
}

function Kpi({ icon: Icon, tone, label, value, to, children }: { icon: LucideIcon; tone: TileTone; label: string; value: string; to?: string; children?: ReactNode }) {
    const body = (
        <>
            <div className="flex items-center gap-3">
                <IconTile tone={tone} size="sm">
                    <Icon />
                </IconTile>
                <span className="text-sm font-medium text-ink-soft">{label}</span>
            </div>
            <p className="mt-2 text-2xl font-semibold tracking-tight">{value}</p>
            <div className="mt-1 min-h-5">{children}</div>
        </>
    );
    const box = "block rounded-xl border border-line bg-white p-3.5 shadow-card";

    return to ? (
        <Link to={to} className={`${box} transition hover:-translate-y-0.5 hover:border-primary/40`}>
            {body}
        </Link>
    ) : (
        <div className={box}>{body}</div>
    );
}

function Panel({ title, to, linkLabel, children, className }: { title: string; to?: string; linkLabel?: string; children: ReactNode; className?: string }) {
    return (
        <Card className={className}>
            <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="text-base font-semibold">{title}</h2>
                {to && linkLabel && (
                    <Link to={to} className="inline-flex min-h-9 items-center gap-0.5 rounded-md px-2 text-sm font-semibold text-primary hover:bg-primary-soft">
                        {linkLabel}
                        <ChevronRight className="size-4" aria-hidden />
                    </Link>
                )}
            </div>
            {children}
        </Card>
    );
}

function SalesPanels({ data }: { data: Dashboard }) {
    const { t, i18n } = useTranslation();
    const locale = dateLocale(i18n.language);
    if (!data.sales || !data.trend || !data.received_today || !data.split_30) return null;

    const points = data.trend.map((day) => {
        const date = localDay(day.date);
        return {
            key: day.date,
            label: String(date.getDate()),
            longLabel: date.toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' }),
            value: day.sales_paise,
            note: t('dashboard.bills_count', { count: day.bills }),
        };
    });
    const received = data.received_today;

    return (
        <div className="grid gap-4 xl:grid-cols-3">
            <Panel title={t('dashboard.sales_trend')} to="/reports/sales_by_day" linkLabel={t('dashboard.see_report')} className="xl:col-span-2">
                <ColumnChart points={points} tableCaption={t('dashboard.sales_trend')} labelHeading={t('sales.date')} valueHeading={t('pos.total')} />
            </Panel>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                <Panel title={t('dashboard.received_today')}>
                    <SplitBar
                        emptyText={t('dashboard.nothing_received')}
                        segments={[
                            { key: 'cash', label: t('pay.cash'), value: received.cash, color: 'var(--color-chart-1)' },
                            { key: 'upi', label: t('pay.upi'), value: received.upi, color: 'var(--color-chart-2)' },
                            { key: 'card', label: t('pay.card'), value: received.card, color: 'var(--color-chart-3)' },
                            { key: 'bank', label: t('pay.bank'), value: received.bank, color: 'var(--color-chart-4)' },
                        ]}
                    />
                </Panel>
                <Panel title={t('dashboard.split_30')}>
                    <SplitBar
                        emptyText={t('dashboard.no_sales_yet')}
                        segments={[
                            { key: 'medical', label: t('products.type.medical'), value: data.split_30.medical_paise, color: 'var(--color-chart-medical)' },
                            { key: 'general', label: t('products.type.general'), value: data.split_30.general_paise, color: 'var(--color-chart-general)' },
                        ]}
                    />
                </Panel>
            </div>
        </div>
    );
}

function KpiRow({ data }: { data: Dashboard }) {
    const { t } = useTranslation();
    const { can } = useSession();
    const udhar = (
        <Kpi icon={HandCoins} tone="warning" label={t('udhar.to_collect')} value={formatMoney(data.udhar.total_paise)} to="/udhar">
            <span className="text-sm text-ink-soft">{t('udhar.customers_owing', { count: data.udhar.customers })}</span>
        </Kpi>
    );
    const bills = (totals: SalesTotals) => <span className="text-sm text-ink-soft">{t('dashboard.bills_count', { count: totals.bills })}</span>;
    const dues = data.supplier_dues && (
        <Kpi icon={Truck} tone="general" label={t('dashboard.supplier_dues')} value={formatMoney(data.supplier_dues.total_paise)} to="/suppliers">
            <span className="text-sm text-ink-soft">
                {data.supplier_dues.overdue_paise > 0
                    ? t('dashboard.supplier_overdue', { amount: formatMoney(data.supplier_dues.overdue_paise) })
                    : data.supplier_dues.drafts > 0
                      ? t('dashboard.purchase_drafts', { count: data.supplier_dues.drafts })
                      : t('suppliers.suppliers_owed', { count: data.supplier_dues.suppliers })}
            </span>
        </Kpi>
    );

    const expenses = data.expenses_today !== undefined && (
        <Kpi icon={Coins} tone="danger" label={t('dashboard.expenses_today')} value={formatMoney(data.expenses_today.total_paise)} to="/expenses">
            <span className="text-sm text-ink-soft">{data.day ? (data.day.status === 'open' ? t('dashboard.day_open') : t('dashboard.day_closed')) : t('dashboard.day_closed')}</span>
        </Kpi>
    );

    if (!data.sales) {
        return (
            <div className="grid grid-cols-2 gap-3 sm:gap-4">
                {can('sales.create') && (
                    <Kpi icon={ReceiptText} tone="success" label={t('dashboard.my_sales_today')} value={formatMoney(data.mine.sales_paise)} to="/sales">
                        {bills(data.mine)}
                    </Kpi>
                )}
                {udhar}
                {dues}
                {expenses}
            </div>
        );
    }

    const { today, yesterday, month, last_month_to_date: lastMonth } = data.sales;

    return (
        <div className="stagger grid grid-cols-2 gap-3 xl:grid-cols-4">
            <Kpi icon={Wallet} tone="success" label={t('dashboard.sales_today')} value={formatMoney(today.sales_paise)} to="/reports/bills">
                <span className="flex flex-wrap items-center gap-x-3">
                    {bills(today)}
                    <Change now={today.sales_paise} before={yesterday.sales_paise} label={t('dashboard.vs_yesterday')} />
                </span>
            </Kpi>
            <Kpi icon={BarChart3} tone="primary" label={t('dashboard.sales_month')} value={formatMoney(month.sales_paise)} to="/reports/sales_by_day">
                <Change now={month.sales_paise} before={lastMonth.sales_paise} label={t('dashboard.vs_last_month')} />
            </Kpi>
            {udhar}
            {today.profit_paise !== undefined && month.profit_paise !== undefined ? (
                <Kpi icon={TrendingUp} tone="medical" label={t('dashboard.profit_today')} value={formatMoney(today.profit_paise)} to="/reports/sales_by_item">
                    <span className="text-sm text-ink-soft">{t('dashboard.profit_month', { amount: formatMoney(month.profit_paise) })}</span>
                </Kpi>
            ) : (
                <Kpi icon={ReceiptText} tone="general" label={t('dashboard.my_sales_today')} value={formatMoney(data.mine.sales_paise)}>
                    {bills(data.mine)}
                </Kpi>
            )}
            {dues}
            {expenses}
        </div>
    );
}

function Lists({ data }: { data: Dashboard }) {
    const { t, i18n } = useTranslation();
    const unit = useUnitLabel();
    const locale = dateLocale(i18n.language);
    const row = 'flex min-h-10 items-center justify-between gap-3 rounded-lg px-2 py-1 text-sm hover:bg-primary-soft/40';
    const nothing = (text: string) => <p className="rounded-lg bg-surface px-3 py-3 text-ink-soft">{text}</p>;

    return (
        <div className="stagger grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {data.top_products && (
                <Panel title={t('dashboard.top_products')} to="/reports/sales_by_item" linkLabel={t('dashboard.see_report')}>
                    {data.top_products.length === 0
                        ? nothing(t('dashboard.no_sales_yet'))
                        : (
                              <RankList
                                  rows={data.top_products.map((product) => ({
                                      key: product.id,
                                      label: product.name,
                                      value: product.amount_paise,
                                      color: product.type === 'medical' ? 'var(--color-chart-medical)' : 'var(--color-chart-general)',
                                  }))}
                              />
                          )}
                    {data.top_products.length > 0 && (
                        <p className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-soft">
                            <span className="inline-flex items-center gap-1.5">
                                <span className="size-3 rounded-sm bg-chart-medical" aria-hidden />
                                {t('products.type.medical')}
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <span className="size-3 rounded-sm bg-chart-general" aria-hidden />
                                {t('products.type.general')}
                            </span>
                        </p>
                    )}
                </Panel>
            )}

            <Panel title={t('dashboard.low_stock')} to="/reports/reorder" linkLabel={t('dashboard.see_all')}>
                {data.low_stock.length === 0
                    ? nothing(t('dashboard.stock_fine'))
                    : (
                          <ul className="-mx-2 flex flex-col">
                              {data.low_stock.map((product) => (
                                  <li key={product.id}>
                                      <Link to={`/products/${product.id}`} className={row}>
                                          <span className="min-w-0">
                                              <span className="block truncate font-medium">{product.name}</span>
                                              <span className="flex items-center gap-2 text-sm text-ink-soft">
                                                  <TypeBadge type={product.type} />
                                                  {formatQty(product.stock_qty, product.pack_size, product.base_unit, product.pricing_unit, unit)}
                                              </span>
                                          </span>
                                          <StockBadge status={product.stock_status} />
                                      </Link>
                                  </li>
                              ))}
                          </ul>
                      )}
            </Panel>

            <Panel title={t('dashboard.expiring_soon')} to="/expiry" linkLabel={t('dashboard.see_all')}>
                {data.expiring.length === 0
                    ? nothing(t('expiry.none.expiring'))
                    : (
                          <ul className="-mx-2 flex flex-col">
                              {data.expiring.map((batch) => (
                                  <li key={`${batch.product_id}-${batch.batch_no}-${batch.expiry_date}`}>
                                      <Link to={`/products/${batch.product_id}`} className={row}>
                                          <span className="min-w-0">
                                              <span className="block truncate font-medium">{batch.name}</span>
                                              <span className="block text-sm text-ink-soft">
                                                  {[batch.batch_no, localDay(batch.expiry_date).toLocaleDateString(locale, { month: 'short', year: 'numeric' })].filter(Boolean).join(' · ')}
                                              </span>
                                          </span>
                                          <ExpiryBadge status={batch.days_left < 0 ? 'expired' : 'within_30'} days={batch.days_left} />
                                      </Link>
                                  </li>
                              ))}
                          </ul>
                      )}
            </Panel>

            {data.recent_bills && (
                <Panel title={t('dashboard.recent_bills')} to="/sales" linkLabel={t('dashboard.see_all')}>
                    {data.recent_bills.length === 0
                        ? nothing(t('dashboard.no_sales_yet'))
                        : (
                              <ul className="-mx-2 flex flex-col">
                                  {data.recent_bills.map((bill) => (
                                      <li key={bill.id}>
                                          <Link to={`/sales/${bill.id}`} className={row}>
                                              <span className="min-w-0">
                                                  <span className="block truncate font-medium">{bill.customer ?? t('pos.walk_in')}</span>
                                                  <span className="block text-sm text-ink-soft">
                                                      {bill.invoice_no} · {new Date(bill.at).toLocaleString(locale, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}
                                                  </span>
                                              </span>
                                              <span className="shrink-0 text-right">
                                                  <span className="block font-semibold">{formatMoney(bill.total_paise)}</span>
                                                  {bill.credit_paise > 0 && <Badge tone="warning">{t('bill.udhar')}</Badge>}
                                              </span>
                                          </Link>
                                      </li>
                                  ))}
                              </ul>
                          )}
                </Panel>
            )}

            <Panel title={t('dashboard.top_udhar')} to="/udhar" linkLabel={t('dashboard.see_all')}>
                {data.udhar.top.length === 0
                    ? nothing(t('udhar.nobody_owes'))
                    : (
                          <ul className="-mx-2 flex flex-col">
                              {data.udhar.top.map((customer) => (
                                  <li key={customer.id}>
                                      <Link to={`/customers/${customer.id}`} className={row}>
                                          <span className="flex min-w-0 items-center gap-3">
                                              <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-warning-soft font-bold uppercase text-warning" aria-hidden>
                                                  {customer.name.slice(0, 1)}
                                              </span>
                                              <span className="min-w-0">
                                                  <span className="block truncate font-medium">{customer.name}</span>
                                                  {customer.village && <span className="block truncate text-sm text-ink-soft">{customer.village}</span>}
                                              </span>
                                          </span>
                                          <span className="shrink-0 font-semibold">{formatMoney(customer.balance_paise)}</span>
                                      </Link>
                                  </li>
                              ))}
                          </ul>
                      )}
            </Panel>
        </div>
    );
}

/**
 * Warns when the last verified backup is too old, and — when a backup folder is set and no copy went there
 * recently — takes one by itself, once per visit, telling the owner what happened.
 */
function BackupReminder({ backup }: { backup: NonNullable<Dashboard['backup']> }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const started = useRef(false);
    const [auto, setAuto] = useState<'idle' | 'running' | 'done' | 'failed'>('idle');

    useEffect(() => {
        if (!backup.auto_wanted || started.current) return;
        started.current = true;
        setAuto('running');
        api<BackupRow | undefined>('/backups/auto', { method: 'POST' })
            .then((row) => {
                if (row && row.status === 'verified' && row.folder_path) {
                    setAuto('done');
                    toast('success', t('dashboard.backup_auto_done', { folder: backup.folder ?? '' }));
                } else if (row && row.status === 'verified') {
                    // Safe on this computer, but the copy did not reach the folder: say why, in words.
                    setAuto('done');
                    toast('error', `${t('backup.done_local')} ${row.warning ? t(`errors.${row.warning}`) : ''}`.trim());
                } else if (row) {
                    setAuto('failed');
                    toast('error', t('dashboard.backup_auto_failed'));
                } else {
                    setAuto('idle');
                }
                void queryClient.invalidateQueries({ queryKey: dashboardKey });
            })
            .catch(() => {
                setAuto('failed');
                toast('error', t('dashboard.backup_auto_failed'));
            });
    }, [backup.auto_wanted, backup.folder, queryClient, t, toast]);

    if (auto === 'running') return <Alert tone="info">{t('dashboard.backup_auto_running')}</Alert>;
    if (!backup.due && auto !== 'failed') return null;

    return (
        <Alert tone="warning">
            <span className="flex flex-wrap items-center justify-between gap-2">
                <span>{auto === 'failed' ? t('dashboard.backup_auto_failed') : backup.last_verified_at === null ? t('dashboard.backup_none') : t('dashboard.backup_old')}</span>
                <Link to="/settings/backup" className="font-semibold underline">
                    {t('dashboard.backup_open')}
                </Link>
            </span>
        </Alert>
    );
}

type Step = { title: string; help: string; done: boolean; to: string; note?: string };

/** Shown only until the shop's own details are complete. */
function GettingStarted() {
    const { t } = useTranslation();
    const { can } = useSession();
    const shop = useShop();
    const canManageUsers = can('users.manage');
    const users = useUsers(canManageUsers);

    if (!shop.data) return null;
    const s = shop.data;
    const steps: Step[] = [
        { title: t('dashboard.step_shop'), help: t('dashboard.step_shop_help'), done: Boolean(s.address && s.village && s.phone), to: '/settings/shop' },
        {
            title: t('dashboard.step_gst'),
            help: t('dashboard.step_gst_help'),
            done: s.gst_mode === 'unregistered' || Boolean(s.gstin),
            note: s.gst_mode === 'unregistered' ? t('dashboard.gst_unregistered_note') : undefined,
            to: '/settings/shop',
        },
        {
            title: t('dashboard.step_licence'),
            help: t('dashboard.step_licence_help'),
            done: Boolean(s.settings.drug_licence_20 || s.settings.drug_licence_21),
            to: '/settings/shop',
        },
    ];
    if (canManageUsers) {
        steps.push({ title: t('dashboard.step_users'), help: t('dashboard.step_users_help'), done: (users.data?.length ?? 0) > 1, to: '/settings/users' });
    }
    if (steps.every((step) => step.done)) return null;

    const canEditShop = can('settings.manage');

    return (
        <Card title={t('dashboard.getting_started')}>
            <ul className="flex flex-col divide-y divide-line">
                {steps.map((step) => (
                    <li key={step.title} className="flex flex-wrap items-center gap-3 py-3">
                        {step.done ? <CheckCircle2 className="size-7 shrink-0 text-success" aria-hidden /> : <Circle className="size-7 shrink-0 text-warning" aria-hidden />}
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold">{step.title}</p>
                            <p className="text-sm text-ink-soft">{step.note ?? step.help}</p>
                        </div>
                        <Badge tone={step.done ? 'success' : 'warning'}>{step.done ? t('dashboard.done') : t('dashboard.todo')}</Badge>
                        {canEditShop ? (
                            <Link to={step.to} className="inline-flex min-h-11 items-center rounded-lg border border-line bg-white px-4 font-semibold shadow-xs hover:bg-primary-soft/40">
                                {t('dashboard.open')}
                            </Link>
                        ) : (
                            !step.done && <span className="text-sm text-ink-soft">{t('dashboard.ask_owner')}</span>
                        )}
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/** The home screen: what happened today, what needs attention, and one-tap ways to the daily jobs. */
export function DashboardPage() {
    const { t, i18n } = useTranslation();
    const { user, can } = useSession();
    const dashboard = useDashboard();
    const quick = QUICK.filter((item) => !item.permission || can(item.permission));

    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <Mascot mood="hello" className="h-14 w-12 shrink-0" />
                <div>
                <h1 className="text-xl font-semibold tracking-tight">{t(greetingKey(), { name: user.name })}</h1>
                <p className="text-sm text-ink-soft">{new Date().toLocaleDateString(dateLocale(i18n.language), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
                </div>
            </div>

            <section aria-label={t('nav.quick_links')} className="-mx-3 flex gap-2 overflow-x-auto px-3 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
                {quick.map((item) => (
                    <Link
                        key={item.to}
                        to={item.to}
                        className="flex shrink-0 items-center gap-2 rounded-lg border border-line bg-white py-1.5 pl-1.5 pr-3 text-sm font-medium shadow-card transition-colors hover:border-primary/40 hover:bg-primary-soft/40"
                    >
                        <IconTile tone={item.tone} size="sm">
                            <item.icon />
                        </IconTile>
                        {t(item.label)}
                    </Link>
                ))}
            </section>

            {dashboard.data?.backup && <BackupReminder backup={dashboard.data.backup} />}

            {dashboard.isPending && <Spinner label={t('common.loading')} shape="tiles" />}
            {dashboard.data && <KpiRow data={dashboard.data} />}

            <StockOverview />

            {dashboard.isPending && <Spinner label={t('common.loading')} shape="rows" />}
            {dashboard.data && <SalesPanels data={dashboard.data} />}
            {dashboard.data && <Lists data={dashboard.data} />}

            <GettingStarted />
        </div>
    );
}
