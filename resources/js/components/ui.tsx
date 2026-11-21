/**
 * The small set of building blocks every screen uses.
 * Sizes follow docs/UI_UX_GUIDELINES.md: controls are at least 44px tall, text 16px.
 */
import { clsx } from 'clsx';
import { Loader2, X } from 'lucide-react';
import { Mascot, type MascotMood } from './Mascot';
import {
    useEffect,
    useId,
    useRef,
    type ComponentProps,
    type InputHTMLAttributes,
    type ReactNode,
    type SelectHTMLAttributes,
} from 'react';

type ButtonProps = ComponentProps<'button'> & {
    variant?: 'primary' | 'success' | 'secondary' | 'danger' | 'ghost';
    size?: 'md' | 'lg';
    busy?: boolean;
    icon?: ReactNode;
};

const buttonVariants: Record<NonNullable<ButtonProps['variant']>, string> = {
    primary: 'bg-primary text-white shadow-xs hover:bg-primary-dark',
    success: 'bg-success text-white shadow-xs hover:bg-success-dark',
    danger: 'bg-danger text-white shadow-xs hover:bg-danger-dark',
    secondary: 'bg-white text-ink border border-line shadow-xs hover:border-primary/40 hover:bg-primary-soft/40',
    ghost: 'text-ink-soft hover:bg-ink/5',
};

export function Button({ variant = 'primary', size = 'md', busy, icon, className, children, disabled, ...rest }: ButtonProps) {
    return (
        <button
            type="button"
            {...rest}
            disabled={disabled || busy}
            className={clsx(
                'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition active:scale-[0.98]',
                'disabled:cursor-not-allowed disabled:opacity-55 disabled:active:scale-100',
                size === 'lg' ? 'min-h-13 px-6 text-lg' : 'min-h-10 px-3.5 text-sm',
                buttonVariants[variant],
                className,
            )}
        >
            {busy ? <Loader2 className="size-5 animate-spin" aria-hidden /> : icon}
            {children}
        </button>
    );
}

type FieldShellProps = {
    label: string;
    id: string;
    error?: string;
    help?: string;
    optional?: string;
    children: ReactNode;
};

function FieldShell({ label, id, error, help, optional, children }: FieldShellProps) {
    return (
        <div>
            <label htmlFor={id} className="mb-1 block text-sm font-medium">
                {label}
                {optional && <span className="ml-2 text-sm font-normal text-ink-soft">({optional})</span>}
            </label>
            {children}
            {error ? (
                <p id={`${id}-error`} role="alert" className="mt-1 text-sm font-medium text-danger">
                    {error}
                </p>
            ) : (
                help && (
                    <p id={`${id}-help`} className="mt-1 text-sm text-ink-soft">
                        {help}
                    </p>
                )
            )}
        </div>
    );
}

const controlClass = (error?: string) =>
    clsx(
        'block min-h-10 w-full rounded-lg border bg-white px-3 text-base text-ink transition-shadow placeholder:text-ink-soft/70',
        'focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15',
        'disabled:bg-surface disabled:text-ink-soft',
        error ? 'border-danger' : 'border-line',
    );

type TextFieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange'> & {
    label: string;
    error?: string;
    help?: string;
    optional?: string;
    onChange: (value: string) => void;
};

export function TextField({ label, error, help, optional, onChange, className, ...rest }: TextFieldProps) {
    const id = useId();
    return (
        <FieldShell label={label} id={id} error={error} help={help} optional={optional}>
            <input
                id={id}
                {...rest}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : help ? `${id}-help` : undefined}
                className={clsx(controlClass(error), className)}
            />
        </FieldShell>
    );
}

type SelectFieldProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange'> & {
    label: string;
    error?: string;
    help?: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
};

export function SelectField({ label, error, help, options, onChange, ...rest }: SelectFieldProps) {
    const id = useId();
    return (
        <FieldShell label={label} id={id} error={error} help={help}>
            <select id={id} {...rest} onChange={(e) => onChange(e.target.value)} className={controlClass(error)}>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </FieldShell>
    );
}

type CheckFieldProps = {
    label: string;
    help?: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
};

/** A large tick box whose whole row can be clicked. */
export function CheckField({ label, help, checked, disabled, onChange }: CheckFieldProps) {
    return (
        <label
            className={clsx(
                'flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 transition-colors',
                checked ? 'border-primary bg-primary-soft' : 'border-line bg-white',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <input
                type="checkbox"
                className="mt-0.5 size-5 shrink-0 accent-primary"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onChange(e.target.checked)}
            />
            <span>
                <span className="block font-medium">{label}</span>
                {help && <span className="block text-sm text-ink-soft">{help}</span>}
            </span>
        </label>
    );
}

export function Card({ title, children, className }: { title?: string; children: ReactNode; className?: string }) {
    return (
        <section className={clsx('rounded-xl border border-line bg-white p-4 shadow-card', className)}>
            {title && <h2 className="mb-3 text-base font-semibold">{title}</h2>}
            {children}
        </section>
    );
}

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'medical';

const badgeTones: Record<Tone, string> = {
    success: 'bg-success-soft text-success-dark',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger-dark',
    info: 'bg-primary-soft text-primary-dark',
    neutral: 'bg-ink/5 text-ink-soft',
    medical: 'bg-medical-soft text-medical',
};

export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
    return (
        <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold', badgeTones[tone])}>
            {children}
        </span>
    );
}

export function Alert({ tone = 'danger', children }: { tone?: 'danger' | 'warning' | 'success' | 'info'; children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    // A refusal usually appears at the top of a form while the operator is at its bottom (on a phone, under the
    // keyboard). Bring it on screen, or "nothing happened" is what they see.
    useEffect(() => {
        if (tone === 'danger') ref.current?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
        // Once, when it appears: parents mount an Alert per message, and re-rendering must not yank the page back.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div ref={ref} role="alert" className={clsx('rounded-lg px-4 py-3 font-medium', badgeTones[tone])}>
            {children}
        </div>
    );
}

/** One shimmering grey block. Give it the size of the thing it stands in for. */
export function Skeleton({ className }: { className?: string }) {
    return <span className={clsx('skeleton', className)} aria-hidden />;
}

type LoadingShape = 'rows' | 'page' | 'form' | 'tiles';

/**
 * Shown while a screen's data is on its way: grey shapes laid out like the screen that is coming,
 * so nothing jumps when it arrives. The words ("Loading…") are still there for screen readers.
 *
 *  rows — a list or table     page — heading, tiles and a list
 *  form — labelled fields     tiles — a row of number cards
 */
export function Spinner({ label, shape = 'rows' }: { label: string; shape?: LoadingShape }) {
    const rows = (count: number) => (
        <div className="rounded-xl border border-line bg-white shadow-card">
            {Array.from({ length: count }, (_, index) => (
                <div key={index} className="flex items-center gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <Skeleton className="h-4 flex-[3]" />
                    <Skeleton className="hidden h-4 flex-1 sm:block" />
                    <Skeleton className="h-4 flex-1" />
                    <Skeleton className="hidden h-4 w-16 md:block" />
                </div>
            ))}
        </div>
    );
    const tiles = (
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {Array.from({ length: 4 }, (_, index) => (
                <div key={index} className="rounded-xl border border-line bg-white p-3.5 shadow-card">
                    <div className="flex items-center gap-3">
                        <Skeleton className="size-8" />
                        <Skeleton className="h-3.5 w-24" />
                    </div>
                    <Skeleton className="mt-3 h-7 w-32" />
                    <Skeleton className="mt-2 h-3 w-20" />
                </div>
            ))}
        </div>
    );

    return (
        <div role="status" className="flex flex-col gap-4">
            <span className="sr-only">{label}</span>
            {shape === 'page' && (
                <>
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex flex-col gap-2">
                            <Skeleton className="h-6 w-56" />
                            <Skeleton className="h-3.5 w-40" />
                        </div>
                        <Skeleton className="h-10 w-28" />
                    </div>
                    {tiles}
                    {rows(5)}
                </>
            )}
            {shape === 'tiles' && tiles}
            {shape === 'rows' && rows(7)}
            {shape === 'form' && (
                <>
                    <Skeleton className="h-6 w-48" />
                    <div className="grid gap-4 rounded-xl border border-line bg-white p-4 shadow-card sm:grid-cols-2">
                        {Array.from({ length: 8 }, (_, index) => (
                            <div key={index} className="flex flex-col gap-2">
                                <Skeleton className="h-3.5 w-28" />
                                <Skeleton className="h-10 w-full" />
                            </div>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

type ModalProps = {
    title: string;
    open: boolean;
    onClose: () => void;
    closeLabel: string;
    /** Keep the content alive while the dialog is hidden (e.g. while another dialog is used in between). */
    keepMounted?: boolean;
    children: ReactNode;
};

/** Built on the browser's own <dialog>: focus is trapped and Esc closes it. */
export function Modal({ title, open, onClose, closeLabel, keepMounted = false, children }: ModalProps) {
    const ref = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            dialog.showModal();
            // The browser puts the cursor on the first button (the X). An operator wants to start typing.
            const field = dialog.querySelector<HTMLElement>('[data-autofocus], .dialog-body input:not([type=checkbox]):not([type=file]):not([disabled]), .dialog-body select:not([disabled]), .dialog-body textarea');
            field?.focus();
            if (field instanceof HTMLInputElement) field.select();
        }
        if (!open && dialog.open) dialog.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            // Esc asks to close. Closing the dialog from code (because `open` changed) must not
            // be reported back as the user closing it, so the native close event is not used.
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            className="m-auto max-h-[92dvh] w-[calc(100%-1.5rem)] max-w-2xl overflow-y-auto rounded-xl p-0 shadow-pop"
        >
            {(open || keepMounted) && (
                <>
                    <header className="sticky top-0 z-10 flex items-center justify-between border-b border-line bg-white px-5 py-3">
                        <h2 className="text-xl font-semibold">{title}</h2>
                        <Button variant="ghost" onClick={onClose} aria-label={closeLabel} icon={<X className="size-5" aria-hidden />} />
                    </header>
                    <div className="dialog-body p-4 sm:p-5">{children}</div>
                </>
            )}
        </dialog>
    );
}

/** Title row of a page: heading, an optional line under it, and the page's main buttons on the right. */
export function PageHeader({ title, subtitle, children }: { title: string; subtitle?: string; children?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div className="min-w-0">
                <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                {subtitle && <p className="text-sm text-ink-soft">{subtitle}</p>}
            </div>
            {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
        </div>
    );
}

/** A friendly "nothing here" block with a picture, so an empty screen never looks broken. */
export function EmptyState({ title, children, mood = 'empty' }: { icon?: ReactNode; title: string; children?: ReactNode; mood?: MascotMood }) {
    return (
        <div className="animate-rise flex flex-col items-center gap-2 rounded-xl border border-dashed border-line bg-white px-6 py-8 text-center">
            <Mascot mood={mood} className="h-28 w-24" />
            <p className="text-base font-semibold">{title}</p>
            {children}
        </div>
    );
}

const tileTones = {
    primary: 'bg-primary-soft text-primary',
    success: 'bg-success-soft text-success-dark',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger-dark',
    medical: 'bg-medical-soft text-medical',
    general: 'bg-general-soft text-general',
    neutral: 'bg-ink/5 text-ink-soft',
} as const;

export type TileTone = keyof typeof tileTones;

/** The rounded coloured square that carries an icon beside a number or a name. */
export function IconTile({ tone = 'primary', size = 'md', children }: { tone?: TileTone; size?: 'sm' | 'md' | 'lg'; children: ReactNode }) {
    return (
        <span
            aria-hidden
            className={clsx(
                'flex shrink-0 items-center justify-center rounded-lg [&>svg]:size-[55%]',
                size === 'sm' ? 'size-8' : size === 'lg' ? 'size-12' : 'size-9',
                tileTones[tone],
            )}
        >
            {children}
        </span>
    );
}
