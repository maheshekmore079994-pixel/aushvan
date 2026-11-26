import { useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import {
    BarChart3,
    Boxes,
    CalendarClock,
    ChevronDown,
    DatabaseBackup,
    HandCoins,
    LayoutDashboard,
    LogOut,
    Menu,
    PackagePlus,
    PanelLeftClose,
    PanelLeftOpen,
    Pill,
    Plus,
    ReceiptText,
    ScanBarcode,
    Search,
    ShieldCheck,
    ShoppingBasket,
    ShoppingCart,
    Store,
    Users,
    WifiOff,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useToast } from '@/components/toast';
import { meKey, useSession, useSignOut, type Profile } from '@/features/auth/session';
import { api } from '@/lib/api';
import type { LanguageCode } from '@/lib/i18n';
import { CommandPalette } from './CommandPalette';
import { LanguageSwitch } from './LanguageSwitch';

type NavItem = { to: string; label: string; icon: LucideIcon; permission?: string; ownerOnly?: boolean };

/**
 * Only screens that exist are listed. Each development phase adds its items
 * here (Purchases, Day Closing, …) — see docs/UI_UX_GUIDELINES.md.
 */
const NAV: { heading?: string; items: NavItem[] }[] = [
    { items: [{ to: '/', label: 'nav.dashboard', icon: LayoutDashboard }] },
    {
        heading: 'nav.group_sell',
        items: [
            { to: '/sales', label: 'nav.sales', icon: ReceiptText, permission: 'sales.create' },
            { to: '/udhar', label: 'nav.udhar', icon: HandCoins },
        ],
    },
    {
        heading: 'nav.group_stock',
        items: [
            { to: '/products', label: 'nav.products', icon: Boxes },
            { to: '/stock/medical', label: 'nav.medical_stock', icon: Pill },
            { to: '/stock/general', label: 'nav.general_stock', icon: ShoppingBasket },
            { to: '/expiry', label: 'nav.expiry', icon: CalendarClock },
            { to: '/labels', label: 'nav.labels', icon: ScanBarcode, permission: 'products.manage' },
        ],
    },
    { heading: 'nav.group_reports', items: [{ to: '/reports', label: 'nav.reports', icon: BarChart3, permission: 'reports.view' }] },
    {
        heading: 'nav.settings',
        items: [
            { to: '/settings/shop', label: 'nav.shop', icon: Store },
            { to: '/settings/users', label: 'nav.users', icon: Users, permission: 'users.manage' },
            { to: '/settings/roles', label: 'nav.roles', icon: ShieldCheck, ownerOnly: true },
            { to: '/settings/backup', label: 'nav.backup', icon: DatabaseBackup, permission: 'backup.manage' },
        ],
    },
];

/** Shortcuts offered from the "+" button on every screen. */
const QUICK: NavItem[] = [
    { to: '/sale', label: 'nav.new_sale', icon: ShoppingCart, permission: 'sales.create' },
    { to: '/products/new', label: 'products.add', icon: PackagePlus, permission: 'products.manage' },
    { to: '/udhar', label: 'udhar.receive', icon: HandCoins, permission: 'credit.receive' },
    { to: '/expiry', label: 'nav.expiry', icon: CalendarClock },
    { to: '/labels', label: 'nav.labels', icon: ScanBarcode, permission: 'products.manage' },
    { to: '/reports', label: 'nav.reports', icon: BarChart3, permission: 'reports.view' },
];

const COLLAPSED_KEY = 'rmgsms.menu_collapsed';

function useOnline(): boolean {
    const [online, setOnline] = useState(navigator.onLine);

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}

/** A button that opens a small panel under it; closes on Esc or a click elsewhere. */
function Popover({ label, button, align = 'left', children }: { label: string; button: ReactNode; align?: 'left' | 'right'; children: (close: () => void) => ReactNode }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent | KeyboardEvent) => {
            if (event instanceof KeyboardEvent ? event.key === 'Escape' : !ref.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                aria-label={label}
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-line bg-white px-2.5 font-medium hover:border-primary/40 hover:bg-primary-soft/40"
            >
                {button}
            </button>
            {open && (
                <div className={clsx('animate-pop absolute top-full z-40 mt-1.5 w-60 rounded-xl border border-line bg-white p-1.5 shadow-pop', align === 'right' ? 'right-0' : 'left-0')}>
                    {children(() => setOpen(false))}
                </div>
            )}
        </div>
    );
}

export function AppLayout() {
    const { t } = useTranslation();
    const { user, can } = useSession();
    const signOut = useSignOut();
    const toast = useToast();
    const queryClient = useQueryClient();
    const online = useOnline();
    const location = useLocation();
    const [menuOpen, setMenuOpen] = useState(false);
    const [searching, setSearching] = useState(false);
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem(COLLAPSED_KEY) === '1';
        } catch {
            return false;
        }
    });

    useEffect(() => setMenuOpen(false), [location.pathname]);

    // Ctrl+K (or Cmd+K) opens search from anywhere.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                if (document.querySelector('dialog[open]')) return; // a dialog is in front; search would open underneath it
                setSearching(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const toggleCollapsed = () =>
        setCollapsed((value) => {
            try {
                localStorage.setItem(COLLAPSED_KEY, value ? '0' : '1');
            } catch {
                // Not remembered; still collapsed for this visit.
            }
            return !value;
        });

    const allowed = (item: NavItem) => (!item.permission || can(item.permission)) && (!item.ownerOnly || user.roles.includes('owner'));
    const canSell = can('sales.create');
    const quick = QUICK.filter(allowed);
    const roleNames = user.roles.map((role) => t(`users.role.${role}`, { defaultValue: role })).join(', ');
    // On phones the menu is a full drawer, never the icon-only strip.
    const slim = collapsed && !menuOpen;

    async function saveLanguage(locale: LanguageCode) {
        const profile = await api<Profile>('/auth/locale', { method: 'PUT', body: { locale } }).catch(() => null);
        if (profile) queryClient.setQueryData(meKey, profile);
    }

    const tab = (active: boolean) => clsx('flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs font-semibold', active ? 'text-primary' : 'text-ink-soft');
    const menuRow = 'flex min-h-9 w-full items-center gap-2.5 rounded-lg px-2.5 text-left font-medium hover:bg-primary-soft';

    return (
        <div className="flex min-h-dvh">
            <aside
                className={clsx(
                    'fixed inset-y-0 left-0 z-40 w-64 shrink-0 flex-col border-r border-line bg-side lg:sticky lg:top-0 lg:flex lg:h-dvh print:hidden!',
                    menuOpen ? 'flex' : 'hidden',
                    slim ? 'lg:w-15' : 'lg:w-56',
                )}
            >
                <div className={clsx('flex h-12 items-center gap-2.5 border-b border-line', slim ? 'justify-center px-2' : 'px-3')}>
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-white" aria-hidden>
                        <Store className="size-4.5" />
                    </span>
                    {!slim && (
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold leading-tight">{user.store.name}</span>
                            {user.store.village && <span className="block truncate text-xs text-ink-soft">{user.store.village}</span>}
                        </span>
                    )}
                    <button type="button" className="rounded-md p-1.5 text-ink-soft hover:bg-ink/5 lg:hidden" onClick={() => setMenuOpen(false)} aria-label={t('common.close')}>
                        <X className="size-5" aria-hidden />
                    </button>
                </div>

                {canSell && (
                    <div className={clsx('pt-3', slim ? 'px-2' : 'px-3')}>
                        <NavLink
                            to="/sale"
                            title={t('nav.new_sale')}
                            className="flex min-h-10 items-center justify-center gap-2 rounded-lg bg-success font-semibold text-white shadow-xs hover:bg-success-dark"
                        >
                            <ShoppingCart className="size-5 shrink-0" aria-hidden />
                            <span className={clsx(slim && 'sr-only')}>{t('nav.new_sale')}</span>
                        </NavLink>
                    </div>
                )}

                <nav className={clsx('flex-1 overflow-y-auto pb-3', slim ? 'px-2' : 'px-3')} aria-label={t('nav.menu')}>
                    {NAV.map((group, index) => {
                        const items = group.items.filter(allowed);
                        if (items.length === 0) return null;
                        return (
                            <div key={index} className="mt-3">
                                {group.heading &&
                                    (slim ? (
                                        <div className="mx-2 mb-2 border-t border-line" aria-hidden />
                                    ) : (
                                        <p className="px-2.5 pb-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-ink-soft/80">{t(group.heading)}</p>
                                    ))}
                                {items.map((item) => (
                                    <NavLink
                                        key={item.to}
                                        to={item.to}
                                        end={item.to === '/'}
                                        title={slim ? t(item.label) : undefined}
                                        className={({ isActive }) =>
                                            clsx(
                                                'flex min-h-9 items-center gap-2.5 rounded-lg text-sm font-medium transition-colors',
                                                slim ? 'justify-center' : 'px-2.5',
                                                isActive ? 'bg-primary-soft text-primary-dark' : 'text-ink/80 hover:bg-ink/5 hover:text-ink',
                                            )
                                        }
                                    >
                                        <item.icon className="size-4.5 shrink-0" aria-hidden />
                                        <span className={clsx(slim && 'sr-only')}>{t(item.label)}</span>
                                    </NavLink>
                                ))}
                            </div>
                        );
                    })}
                </nav>

                <button
                    type="button"
                    onClick={toggleCollapsed}
                    aria-label={t(collapsed ? 'nav.expand_menu' : 'nav.collapse_menu')}
                    title={t(collapsed ? 'nav.expand_menu' : 'nav.collapse_menu')}
                    className={clsx('hidden min-h-10 items-center gap-2.5 border-t border-line text-sm font-medium text-ink-soft hover:bg-ink/5 lg:flex', slim ? 'justify-center' : 'px-5')}
                >
                    {collapsed ? <PanelLeftOpen className="size-4.5" aria-hidden /> : <PanelLeftClose className="size-4.5" aria-hidden />}
                    {!slim && t('nav.collapse_menu')}
                </button>
            </aside>
            {menuOpen && <div className="fixed inset-0 z-30 bg-rail/40 lg:hidden" onClick={() => setMenuOpen(false)} aria-hidden />}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-20 flex h-12 items-center gap-2 border-b border-line bg-white/90 px-3 backdrop-blur lg:px-5 print:hidden">
                    <button type="button" className="rounded-lg p-2 text-ink-soft hover:bg-ink/5 lg:hidden" onClick={() => setMenuOpen((open) => !open)} aria-label={t('nav.menu')}>
                        <Menu className="size-5" aria-hidden />
                    </button>

                    <button
                        type="button"
                        onClick={() => setSearching(true)}
                        aria-label={t('nav.search')}
                        className="flex min-h-9 min-w-0 flex-1 items-center gap-2 rounded-lg border border-line bg-surface px-3 text-left text-ink-soft hover:border-primary/40 sm:max-w-sm"
                    >
                        <Search className="size-4 shrink-0" aria-hidden />
                        <span className="min-w-0 flex-1 truncate text-sm">{t('nav.search_placeholder')}</span>
                        <kbd className="hidden rounded border border-line bg-white px-1.5 text-xs sm:inline">Ctrl K</kbd>
                    </button>

                    <div className="ml-auto flex items-center gap-2">
                        {!online && (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-danger-soft px-2.5 py-1 text-sm font-semibold text-danger-dark" role="status">
                                <WifiOff className="size-4" aria-hidden />
                                <span className="hidden sm:inline">{t('app.no_connection')}</span>
                            </span>
                        )}
                        {quick.length > 0 && (
                            <Popover
                                label={t('nav.quick_links')}
                                align="right"
                                button={
                                    <>
                                        <Plus className="size-4" aria-hidden />
                                        <span className="hidden text-sm md:inline">{t('nav.quick_links')}</span>
                                    </>
                                }
                            >
                                {(close) =>
                                    quick.map((item) => (
                                        <Link key={item.to + item.label} to={item.to} onClick={close} className={menuRow}>
                                            <item.icon className="size-4 shrink-0 text-primary" aria-hidden />
                                            {t(item.label)}
                                        </Link>
                                    ))
                                }
                            </Popover>
                        )}
                        <Popover
                            label={t('nav.account')}
                            align="right"
                            button={
                                <>
                                    <span className="flex size-6 items-center justify-center rounded-full bg-primary text-xs font-bold uppercase text-white" aria-hidden>
                                        {user.name.slice(0, 1)}
                                    </span>
                                    <span className="hidden max-w-32 truncate text-sm md:inline">{user.name}</span>
                                    <ChevronDown className="size-4 text-ink-soft" aria-hidden />
                                </>
                            }
                        >
                            {(close) => (
                                <>
                                    <div className="px-2.5 py-2">
                                        <p className="truncate font-semibold">{user.name}</p>
                                        <p className="truncate text-sm text-ink-soft">{roleNames}</p>
                                    </div>
                                    <div className="border-t border-line px-2.5 py-2">
                                        <LanguageSwitch compact onChange={saveLanguage} />
                                    </div>
                                    <div className="border-t border-line pt-1.5">
                                        <button
                                            type="button"
                                            className={clsx(menuRow, 'text-danger-dark hover:bg-danger-soft')}
                                            onClick={() => {
                                                close();
                                                signOut().catch(() => toast('error', t('app.no_connection_help')));
                                            }}
                                        >
                                            <LogOut className="size-4 shrink-0" aria-hidden />
                                            {t('nav.logout')}
                                        </button>
                                    </div>
                                </>
                            )}
                        </Popover>
                    </div>
                </header>
                {!online && (
                    <div className="bg-danger px-4 py-2 text-center font-semibold text-white print:hidden" role="alert">
                        {t('app.no_connection_help')}
                    </div>
                )}
                <main className="mx-auto w-full max-w-[96rem] flex-1 p-3 pb-24 sm:p-4 sm:pb-24 lg:p-5 lg:pb-5 print:max-w-none print:p-0">
                    {/* Re-keyed per screen so each screen eases in. */}
                    <div key={location.pathname} className="animate-rise">
                        <Outlet />
                    </div>
                </main>

                {/* Phones and small tablets: the places used all day, one thumb away. */}
                <nav className="pb-safe fixed inset-x-0 bottom-0 z-20 flex border-t border-line bg-white lg:hidden print:hidden" aria-label={t('nav.quick_links')}>
                    <NavLink to="/" end className={({ isActive }) => tab(isActive)}>
                        <LayoutDashboard className="size-5" aria-hidden />
                        {t('nav.home')}
                    </NavLink>
                    <NavLink to="/products" className={({ isActive }) => tab(isActive)}>
                        <Boxes className="size-5" aria-hidden />
                        {t('nav.stock_short')}
                    </NavLink>
                    {canSell && (
                        <NavLink to="/sale" className="flex flex-1 flex-col items-center justify-center text-xs font-bold text-success-dark">
                            <span className="-mt-5 flex size-12 items-center justify-center rounded-full bg-success text-white shadow-pop ring-4 ring-white" aria-hidden>
                                <ShoppingCart className="size-6" />
                            </span>
                            {t('nav.sell_short')}
                        </NavLink>
                    )}
                    <NavLink to="/udhar" className={({ isActive }) => tab(isActive)}>
                        <HandCoins className="size-5" aria-hidden />
                        {t('nav.udhar')}
                    </NavLink>
                    <button type="button" className={tab(false)} onClick={() => setMenuOpen(true)}>
                        <Menu className="size-5" aria-hidden />
                        {t('nav.more')}
                    </button>
                </nav>
            </div>

            <CommandPalette open={searching} onClose={() => setSearching(false)} links={[...QUICK, ...NAV.flatMap((group) => group.items)].filter(allowed)} />
        </div>
    );
}
