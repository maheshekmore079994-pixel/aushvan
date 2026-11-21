import { useQueryClient } from '@tanstack/react-query';
import { Pencil, UserPlus } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Button, Card, CheckField, Modal, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { LANGUAGES } from '@/lib/i18n';
import { meKey } from '@/features/auth/session';
import { ROLES, usersKey, useRoleNames, useUsers, type RoleName, type StaffUser } from './api';

type Form = {
    name: string;
    username: string;
    mobile: string;
    password: string;
    pin: string;
    locale: string;
    is_pharmacist: boolean;
    pharmacist_reg_no: string;
    is_active: boolean;
    roles: RoleName[];
};

const emptyForm: Form = {
    name: '',
    username: '',
    mobile: '',
    password: '',
    pin: '',
    locale: 'en',
    is_pharmacist: false,
    pharmacist_reg_no: '',
    is_active: true,
    roles: ['cashier'],
};

function toForm(user: StaffUser): Form {
    return {
        name: user.name,
        username: user.username,
        mobile: user.mobile ?? '',
        password: '',
        pin: '',
        locale: user.locale,
        is_pharmacist: user.is_pharmacist,
        pharmacist_reg_no: user.pharmacist_reg_no ?? '',
        is_active: user.is_active,
        roles: user.roles,
    };
}

type Editing = { user: StaffUser | null; form: Form } | null;

export function UsersPage() {
    const { t, i18n } = useTranslation();
    const users = useUsers();
    const [editing, setEditing] = useState<Editing>(null);

    if (users.isError) return <Alert>{t('app.error_generic')}</Alert>;
    if (users.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const formatDate = (iso: string | null) =>
        iso ? new Date(iso).toLocaleString(`${i18n.language}-IN-u-nu-latn`, { dateStyle: 'medium', timeStyle: 'short' }) : t('users.never');

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">{t('users.title')}</h1>
                    <p className="text-ink-soft">{t('users.intro')}</p>
                </div>
                <Button onClick={() => setEditing({ user: null, form: emptyForm })} icon={<UserPlus className="size-5" aria-hidden />}>
                    {t('users.add')}
                </Button>
            </div>

            <Card className="overflow-x-auto p-0">
                <table className="w-full min-w-[640px] text-left">
                    <thead className="border-b border-line bg-surface text-sm text-ink-soft">
                        <tr>
                            <th className="px-3 py-2 font-semibold">{t('users.name')}</th>
                            <th className="px-3 py-2 font-semibold">{t('users.roles')}</th>
                            <th className="px-3 py-2 font-semibold">{t('users.status')}</th>
                            <th className="px-3 py-2 font-semibold">{t('users.last_login')}</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {users.data.map((user) => (
                            <tr key={user.id}>
                                <td className="px-3 py-2">
                                    <p className="font-semibold">{user.name}</p>
                                    <p className="text-sm text-ink-soft">{user.username}</p>
                                </td>
                                <td className="px-3 py-2">
                                    <div className="flex flex-wrap gap-1">
                                        {user.roles.map((role) => (
                                            <Badge key={role} tone="info">
                                                {t(`users.role.${role}`, { defaultValue: role })}
                                            </Badge>
                                        ))}
                                        {user.is_pharmacist && <Badge tone="medical">{t('users.registered_badge')}</Badge>}
                                    </div>
                                </td>
                                <td className="px-3 py-2">
                                    <Badge tone={user.is_active ? 'success' : 'neutral'}>{user.is_active ? t('common.active') : t('common.inactive')}</Badge>
                                </td>
                                <td className="px-3 py-2 text-sm text-ink-soft">{formatDate(user.last_login_at)}</td>
                                <td className="px-3 py-2 text-right">
                                    <Button variant="secondary" onClick={() => setEditing({ user, form: toForm(user) })} icon={<Pencil className="size-4" aria-hidden />}>
                                        {t('common.edit')}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>

            <Modal
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={editing?.user ? t('users.edit') : t('users.add')}
                closeLabel={t('common.close')}
            >
                {editing && <UserForm key={editing.user?.id ?? 'new'} editing={editing} onDone={() => setEditing(null)} />}
            </Modal>
        </div>
    );
}

function UserForm({ editing, onDone }: { editing: NonNullable<Editing>; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<Form>(editing.form);
    const [error, setError] = useState<unknown>(null);
    const [busy, setBusy] = useState(false);
    // One key for this "add user" attempt: pressing Save twice cannot create two users.
    const idempotencyKey = useRef(newIdempotencyKey());
    const user = editing.user;

    const set = <K extends keyof Form>(key: K) => (value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));
    const fieldError = (name: string) => (error instanceof ApiError ? error.first(name) : undefined);

    const roleNames = useRoleNames();

    function toggleRole(role: RoleName, on: boolean) {
        setForm((f) => ({ ...f, roles: on ? [...f.roles, role] : f.roles.filter((r) => r !== role) }));
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        const body = {
            ...form,
            name: form.name.trim(),
            mobile: form.mobile.trim() || null,
            password: form.password || null,
            pin: form.pin || null,
            pharmacist_reg_no: form.is_pharmacist ? form.pharmacist_reg_no.trim() || null : null,
        };
        try {
            if (user) await api(`/users/${user.id}`, { method: 'PUT', body });
            else await api('/users', { method: 'POST', body, idempotencyKey: idempotencyKey.current });
            await queryClient.invalidateQueries({ queryKey: usersKey });
            void queryClient.invalidateQueries({ queryKey: meKey });
            toast('success', t('common.saved'));
            onDone();
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    let message: string | null = null;
    if (error instanceof NetworkError) message = t('app.no_connection_help');
    else if (error instanceof ApiError && error.code === 'last_owner') message = t('users.last_owner');
    else if (error instanceof ApiError && error.status === 403) message = t('app.forbidden');
    else if (error && !(error instanceof ApiError && error.status === 422)) message = t('app.error_generic');

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('users.name')} value={form.name} onChange={set('name')} error={fieldError('name')} autoFocus required />
                <TextField label={t('users.username')} value={form.username} onChange={set('username')} error={fieldError('username')} autoCapitalize="none" autoComplete="off" required />
                <TextField
                    label={user ? t('users.password_new') : t('users.password')}
                    type="password"
                    value={form.password}
                    onChange={set('password')}
                    error={fieldError('password')}
                    help={user ? t('users.password_keep') : t('auth.password_help')}
                    autoComplete="new-password"
                    required={!user}
                />
                <TextField label={t('users.mobile')} value={form.mobile} onChange={set('mobile')} error={fieldError('mobile')} inputMode="tel" optional={t('common.optional')} />
            </div>

            <fieldset>
                <legend className="mb-2 font-medium">{t('users.roles')}</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {(roleNames.data ?? ROLES).map((role) => (
                        <CheckField
                            key={role}
                            label={t(`users.role.${role}`, { defaultValue: role })}
                            help={t(`users.role_help.${role}`, { defaultValue: '' }) || undefined}
                            checked={form.roles.includes(role)}
                            onChange={(on) => toggleRole(role, on)}
                        />
                    ))}
                </div>
                {fieldError('roles') && (
                    <p role="alert" className="mt-1 text-sm font-medium text-danger">
                        {fieldError('roles')}
                    </p>
                )}
            </fieldset>

            <div className="grid gap-4 sm:grid-cols-2">
                <CheckField label={t('users.is_pharmacist')} checked={form.is_pharmacist} onChange={set('is_pharmacist')} />
                {form.is_pharmacist && (
                    <TextField label={t('users.pharmacist_reg_no')} value={form.pharmacist_reg_no} onChange={set('pharmacist_reg_no')} error={fieldError('pharmacist_reg_no')} required />
                )}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label={t('users.pin')}
                    type="password"
                    value={form.pin}
                    onChange={(value) => set('pin')(value.replace(/\D/g, '').slice(0, 6))}
                    error={fieldError('pin')}
                    help={user?.has_pin ? t('users.pin_keep') : t('users.pin_help')}
                    inputMode="numeric"
                    autoComplete="off"
                    optional={t('common.optional')}
                />
                <SelectField
                    label={t('common.language')}
                    value={form.locale}
                    onChange={set('locale')}
                    error={fieldError('locale')}
                    options={LANGUAGES.map((l) => ({ value: l.code, label: l.label }))}
                />
            </div>

            {user && <CheckField label={t('common.active')} checked={form.is_active} onChange={set('is_active')} />}

            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy}>
                    {busy ? t('common.saving') : t('common.save')}
                </Button>
            </div>
        </form>
    );
}
