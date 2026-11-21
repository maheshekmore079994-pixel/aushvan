import { Check, Store } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Mascot } from '@/components/Mascot';
import { Alert, Button, Card, TextField } from '@/components/ui';
import { LanguageSwitch } from '@/layout/LanguageSwitch';
import { api, ApiError, NetworkError } from '@/lib/api';
import i18n from '@/lib/i18n';
import { useSignedIn, type Profile } from './session';

function AuthFrame({ title, intro, children }: { title: string; intro?: string; children: ReactNode }) {
    const { t } = useTranslation();

    return (
        <main className="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {/* The welcome side. Hidden on phones, where a small mascot sits above the form instead. */}
            <div className="relative hidden flex-col justify-between overflow-hidden bg-rail p-10 text-white lg:flex">
                <div className="flex items-center gap-3">
                    <span className="flex size-10 items-center justify-center rounded-lg bg-primary">
                        <Store className="size-5" aria-hidden />
                    </span>
                    <span aria-hidden>
                        <span className="block text-lg font-semibold leading-tight tracking-wide">{t('app.name')}</span>
                        <span className="block text-sm text-white/65">{t('app.tagline')}</span>
                    </span>
                </div>
                <div className="flex flex-col items-center gap-4">
                    <span className="animate-rise rounded-2xl rounded-bl-sm bg-white px-4 py-2.5 text-base font-medium text-ink shadow-pop">{t('auth.mascot_hello')}</span>
                    <span className="flex size-64 items-end justify-center rounded-full bg-white/8">
                        <Mascot mood="hello" className="h-60 w-52" />
                    </span>
                </div>
                <ul className="flex flex-col gap-2 text-white/75">
                    {(['auth.point_bill', 'auth.point_stock', 'auth.point_udhar'] as const).map((key) => (
                        <li key={key} className="flex items-center gap-2">
                            <Check className="size-4 shrink-0 text-[#7ee0b8]" aria-hidden />
                            {t(key)}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="flex flex-col items-center justify-center gap-5 bg-surface p-4">
                <div className="flex flex-col items-center gap-1 lg:hidden">
                    <Mascot mood="hello" className="h-24 w-20" />
                    <span className="text-lg font-semibold tracking-wide text-primary-dark">{t('app.name')}</span>
                    <span className="text-sm text-ink-soft">{t('app.tagline')}</span>
                </div>
                <Card className="animate-rise w-full max-w-md p-6">
                    <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                    {intro && <p className="mt-1 text-sm text-ink-soft">{intro}</p>}
                    <div className="mt-5">{children}</div>
                </Card>
                <LanguageSwitch />
                <p className="text-center text-xs text-ink-soft">
                    {t('app.name')} · {t('app.company')} · {t('app.parent_company')}
                </p>
            </div>
        </main>
    );
}

/** Turns a failed request into one message for the top of the form. */
function useFormError() {
    const { t } = useTranslation();
    const [error, setError] = useState<unknown>(null);

    let message: string | null = null;
    if (error instanceof NetworkError) message = t('app.no_connection_help');
    else if (error instanceof ApiError && error.status === 429) message = t('auth.too_many');
    else if (error instanceof ApiError && error.status !== 422) message = error.message;
    else if (error && !(error instanceof ApiError)) message = t('app.error_generic');

    const field = (name: string) => (error instanceof ApiError ? error.first(name) : undefined);

    return { message, field, setError };
}

export function LoginPage() {
    const { t } = useTranslation();
    const signedIn = useSignedIn();
    const { message, field, setError } = useFormError();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            signedIn(await api<Profile>('/auth/login', { method: 'POST', body: { username, password } }));
        } catch (error) {
            setError(error);
            setPassword('');
        } finally {
            setBusy(false);
        }
    }

    return (
        <AuthFrame title={t('auth.login_title')}>
            <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                {message && <Alert>{message}</Alert>}
                <TextField
                    label={t('auth.username')}
                    value={username}
                    onChange={setUsername}
                    error={field('username')}
                    autoFocus
                    autoComplete="username"
                    autoCapitalize="none"
                    required
                />
                <TextField
                    label={t('auth.password')}
                    type="password"
                    value={password}
                    onChange={setPassword}
                    error={field('password')}
                    autoComplete="current-password"
                    required
                />
                <Button type="submit" size="lg" busy={busy}>
                    {busy ? t('auth.signing_in') : t('auth.sign_in')}
                </Button>
            </form>
        </AuthFrame>
    );
}

export function SetupPage() {
    const { t } = useTranslation();
    const signedIn = useSignedIn();
    const { message, field, setError } = useFormError();
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({
        shop_name: '',
        village: '',
        phone: '',
        name: '',
        username: '',
        password: '',
        password_confirmation: '',
    });
    const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            signedIn(await api<Profile>('/setup', { method: 'POST', body: { ...form, locale: i18n.language } }));
        } catch (error) {
            setError(error);
        } finally {
            setBusy(false);
        }
    }

    return (
        <AuthFrame title={t('auth.setup_title')} intro={t('auth.setup_intro')}>
            <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                {message && <Alert>{message}</Alert>}
                <TextField label={t('auth.shop_name')} value={form.shop_name} onChange={set('shop_name')} error={field('shop_name')} autoFocus required />
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField label={t('auth.village')} value={form.village} onChange={set('village')} error={field('village')} optional={t('common.optional')} />
                    <TextField label={t('auth.phone')} value={form.phone} onChange={set('phone')} error={field('phone')} optional={t('common.optional')} inputMode="tel" />
                </div>

                <h2 className="mt-2 border-t border-line pt-4 text-lg font-semibold">{t('auth.owner_section')}</h2>
                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField label={t('auth.your_name')} value={form.name} onChange={set('name')} error={field('name')} autoComplete="name" required />
                    <TextField label={t('auth.username')} value={form.username} onChange={set('username')} error={field('username')} autoComplete="username" autoCapitalize="none" required />
                    <TextField label={t('auth.password')} type="password" value={form.password} onChange={set('password')} error={field('password')} help={t('auth.password_help')} autoComplete="new-password" required />
                    <TextField label={t('auth.confirm_password')} type="password" value={form.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" required />
                </div>
                <Button type="submit" size="lg" variant="success" busy={busy}>
                    {busy ? t('common.saving') : t('auth.create_shop')}
                </Button>
            </form>
        </AuthFrame>
    );
}
