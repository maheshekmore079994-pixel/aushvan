import { CornerDownLeft, Search, type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useProducts } from '@/features/products/api';
import { TypeBadge } from '@/features/products/badges';
import { formatMoney } from '@/lib/money';

export type PaletteLink = { to: string; label: string; icon: LucideIcon };

type Row = { key: string; to: string; icon?: LucideIcon; title: string; note?: string; type?: 'medical' | 'general'; price?: number };

/**
 * "Search or jump to…" (Ctrl+K): type a few letters to open any screen or any product
 * without touching the menu. Arrow keys move, Enter opens, Esc closes.
 */
export function CommandPalette({ open, onClose, links }: { open: boolean; onClose: () => void; links: PaletteLink[] }) {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const input = useRef<HTMLInputElement>(null);
    const [text, setText] = useState('');
    const [term, setTerm] = useState('');
    const [active, setActive] = useState(0);

    useEffect(() => {
        const timer = setTimeout(() => setTerm(text.trim()), 250);
        return () => clearTimeout(timer);
    }, [text]);

    useEffect(() => {
        if (!open) return;
        setText('');
        setTerm('');
        setActive(0);
        input.current?.focus();
    }, [open]);

    const products = useProducts({ search: open && term.length >= 2 ? term : '', type: '', stock: '', page: 1 }, open && term.length >= 2);

    const rows = useMemo<Row[]>(() => {
        const needle = text.trim().toLowerCase();
        const screens = links
            .map((link) => ({ key: `go-${link.to}-${link.label}`, to: link.to, icon: link.icon, title: t(link.label) }))
            .filter((row, index, all) => all.findIndex((other) => other.key === row.key) === index)
            .filter((row) => needle === '' || row.title.toLowerCase().includes(needle));
        const found = (term.length >= 2 ? (products.data?.data ?? []) : []).slice(0, 8).map((product) => ({
            key: `p-${product.id}`,
            to: `/products/${product.id}`,
            title: product.name,
            note: [product.generic_name, product.name_local].filter(Boolean).join(' · ') || undefined,
            type: product.type,
            price: product.selling_price_paise,
        }));
        return [...found, ...screens];
    }, [links, products.data, t, term, text]);

    useEffect(() => setActive(0), [rows.length]);

    if (!open) return null;

    const go = (row: Row | undefined) => {
        if (!row) return;
        onClose();
        navigate(row.to);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-rail/40 p-3 pt-[12vh] backdrop-blur-[2px] print:hidden" onMouseDown={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-label={t('nav.search')}
                className="animate-pop w-full max-w-xl overflow-hidden rounded-xl border border-line bg-white shadow-pop"
                onMouseDown={(event) => event.stopPropagation()}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') onClose();
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setActive((index) => Math.min(index + 1, rows.length - 1));
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setActive((index) => Math.max(index - 1, 0));
                    }
                    if (event.key === 'Enter') go(rows[active]);
                }}
            >
                <div className="flex items-center gap-3 border-b border-line px-4">
                    <Search className="size-5 shrink-0 text-ink-soft" aria-hidden />
                    <input
                        ref={input}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        aria-label={t('nav.search')}
                        placeholder={t('nav.search_placeholder')}
                        className="min-h-12 w-full bg-transparent text-base outline-none focus-visible:outline-none"
                    />
                    <kbd className="rounded border border-line bg-surface px-1.5 py-0.5 text-xs text-ink-soft">Esc</kbd>
                </div>
                <ul className="max-h-[50vh] overflow-y-auto p-1.5" role="listbox" aria-label={t('nav.search')}>
                    {rows.length === 0 && <li className="px-3 py-6 text-center text-ink-soft">{t('products.none_found')}</li>}
                    {rows.map((row, index) => (
                        <li key={row.key} role="option" aria-selected={index === active}>
                            <button
                                type="button"
                                onMouseEnter={() => setActive(index)}
                                onClick={() => go(row)}
                                className={`flex min-h-10 w-full items-center gap-3 rounded-lg px-3 py-1.5 text-left ${index === active ? 'bg-primary-soft' : ''}`}
                            >
                                {row.icon ? <row.icon className="size-4 shrink-0 text-ink-soft" aria-hidden /> : row.type && <TypeBadge type={row.type} />}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">{row.title}</span>
                                    {row.note && <span className="block truncate text-xs text-ink-soft">{row.note}</span>}
                                </span>
                                {row.price !== undefined && <span className="shrink-0 text-sm font-semibold">{formatMoney(row.price)}</span>}
                                {index === active && <CornerDownLeft className="size-4 shrink-0 text-ink-soft" aria-hidden />}
                            </button>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
