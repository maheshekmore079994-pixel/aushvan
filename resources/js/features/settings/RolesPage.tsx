import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Lock, Plus, ShieldCheck, Trash2, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Button, Card, CheckField, Modal, PageHeader, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { meKey } from '@/features/auth/session';
import { api, ApiError } from '@/lib/api';
import { ulid } from '@/lib/ulid';
import { usersKey } from './api';

type Role = { id: number; name: string; builtin: boolean; locked: boolean; permissions: string[]; users: number };
type RolesAnswer = { data: Role[]; permissions: string[] };

const rolesKey = ['roles'] as const;

/** Permissions in the order and groups the owner thinks in. Anything the server adds later lands in "other". */
const GROUPS: Record<string, string[]> = {
    sales: ['sales.create', 'sales.return', 'sales.cancel', 'sales.discount_override', 'sales.price_override'],
    stock: ['products.manage', 'medicines.manage', 'stock.adjust', 'stock.view_cost', 'purchases.manage', 'suppliers.manage'],
    udhar: ['customers.manage', 'credit.receive', 'credit.reverse'],
    medical: ['pharmacist.verify', 'prescriptions.view', 'returns.restock_medicine'],
    money: ['expenses.manage', 'day.close', 'day.reopen', 'reports.view', 'reports.financial'],
    admin: ['settings.manage', 'users.manage', 'audit.view', 'backup.manage'],
};

/** "sales.create" -> "sales_create": translation keys cannot contain a dot. */
const permissionKey = (permission: string) => permission.replace('.', '_');

export function RolesPage() {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const roles = useQuery({ queryKey: rolesKey, queryFn: () => api<RolesAnswer>('/roles') });
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [draft, setDraft] = useState<{ name: string; permissions: string[] }>({ name: '', permissions: [] });
    const [adding, setAdding] = useState(false);
    const [newName, setNewName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [addKey, setAddKey] = useState(() => ulid());

    const selected = roles.data?.data.find((role) => role.id === selectedId) ?? roles.data?.data.find((role) => !role.locked) ?? null;

    useEffect(() => {
        if (selected) setDraft({ name: selected.name, permissions: selected.permissions });
        setError(null);
        // Reset the form only when another role is opened or fresh data arrives for it.
    }, [selected?.id, selected?.permissions.join(',')]);

    const done = (answer: Role, message: string) => {
        void queryClient.invalidateQueries({ queryKey: rolesKey });
        void queryClient.invalidateQueries({ queryKey: usersKey });
        void queryClient.invalidateQueries({ queryKey: meKey });
        setSelectedId(answer.id);
        toast('success', message);
    };
    const fail = (e: unknown) => setError(e instanceof ApiError ? (e.code ? t(`errors.${e.code}`, { defaultValue: e.message }) : (e.first('name') ?? e.message)) : t('app.error_generic'));

    const save = useMutation({
        mutationFn: () => api<Role>(`/roles/${selected?.id}`, { method: 'PUT', body: draft }),
        onSuccess: (answer) => done(answer, t('common.saved')),
        onError: fail,
    });
    const create = useMutation({
        mutationFn: () => api<Role>('/roles', { method: 'POST', body: { name: newName.trim(), permissions: [] }, idempotencyKey: addKey }),
        onSuccess: (answer) => {
            setAdding(false);
            setNewName('');
            setAddKey(ulid());
            done(answer, t('common.saved'));
        },
        onError: fail,
    });
    const remove = useMutation({
        mutationFn: () => api<null>(`/roles/${selected?.id}`, { method: 'DELETE' }),
        onSuccess: () => {
            setSelectedId(null);
            void queryClient.invalidateQueries({ queryKey: rolesKey });
            void queryClient.invalidateQueries({ queryKey: usersKey });
            toast('success', t('roles.deleted'));
        },
        onError: fail,
    });

    if (roles.isError) return <Alert>{t('app.forbidden')}</Alert>;
    if (roles.isPending) return <Spinner label={t('common.loading')} shape="page" />;

    const roleLabel = (role: Role) => (role.builtin ? t(`users.role.${role.name}`, { defaultValue: role.name }) : role.name);
    const grouped = { ...GROUPS, other: roles.data.permissions.filter((p) => !Object.values(GROUPS).flat().includes(p)) };
    const toggle = (permission: string, on: boolean) =>
        setDraft((d) => ({ ...d, permissions: on ? [...d.permissions, permission] : d.permissions.filter((p) => p !== permission) }));
    const changed = selected !== null && (draft.name !== selected.name || [...draft.permissions].sort().join() !== [...selected.permissions].sort().join());

    return (
        <div className="flex flex-col gap-4">
            <PageHeader title={t('roles.title')} subtitle={t('roles.intro')}>
                <Button onClick={() => { setError(null); setAdding(true); }} icon={<Plus className="size-5" aria-hidden />}>
                    {t('roles.add')}
                </Button>
            </PageHeader>

            <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:flex lg:flex-col" role="tablist" aria-label={t('roles.title')}>
                    {roles.data.data.map((role) => {
                        const active = selected?.id === role.id;
                        return (
                            <button
                                key={role.id}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                onClick={() => setSelectedId(role.id)}
                                className={`flex min-w-0 items-center gap-3 rounded-xl border p-3 text-left shadow-card transition ${active ? 'border-primary bg-primary-soft' : 'border-line/70 bg-white hover:border-primary/40'}`}
                            >
                                <span className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${active ? 'bg-primary text-white' : 'bg-ink/5 text-ink-soft'}`} aria-hidden>
                                    {role.locked ? <Lock className="size-5" /> : <ShieldCheck className="size-5" />}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate font-semibold">{roleLabel(role)}</span>
                                    <span className="flex items-center gap-1 text-sm text-ink-soft">
                                        <Users className="size-4" aria-hidden />
                                        {t('roles.users_count', { count: role.users })}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>

                {selected && (
                    <Card>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold">{roleLabel(selected)}</h2>
                                <p className="text-sm text-ink-soft">
                                    {selected.locked ? t('roles.owner_locked') : t('roles.allowed_count', { count: draft.permissions.length, total: roles.data.permissions.length })}
                                </p>
                            </div>
                            {!selected.builtin && (
                                <Button
                                    variant="secondary"
                                    busy={remove.isPending}
                                    onClick={() => { if (window.confirm(t('roles.delete_confirm', { name: selected.name }))) remove.mutate(); }}
                                    icon={<Trash2 className="size-5 text-danger" aria-hidden />}
                                >
                                    {t('roles.delete')}
                                </Button>
                            )}
                            {selected.builtin && !selected.locked && <Badge>{t('roles.builtin')}</Badge>}
                        </div>

                        {error && <div className="mb-4"><Alert>{error}</Alert></div>}

                        {!selected.builtin && (
                            <div className="mb-4 max-w-sm">
                                <TextField label={t('roles.name')} value={draft.name} onChange={(name) => setDraft((d) => ({ ...d, name }))} maxLength={40} />
                            </div>
                        )}

                        <div className="flex flex-col gap-4">
                            {Object.entries(grouped).map(
                                ([group, permissions]) =>
                                    permissions.length > 0 && (
                                        <fieldset key={group}>
                                            <legend className="mb-2 text-sm font-semibold uppercase tracking-wide text-ink-soft">{t(`roles.group.${group}`)}</legend>
                                            <div className="grid gap-2 md:grid-cols-2">
                                                {permissions.map((permission) => (
                                                    <CheckField
                                                        key={permission}
                                                        label={t(`roles.permission.${permissionKey(permission)}`, { defaultValue: permission })}
                                                        help={t(`roles.permission_help.${permissionKey(permission)}`, { defaultValue: '' }) || undefined}
                                                        checked={draft.permissions.includes(permission)}
                                                        disabled={selected.locked}
                                                        onChange={(on) => toggle(permission, on)}
                                                    />
                                                ))}
                                            </div>
                                        </fieldset>
                                    ),
                            )}
                        </div>

                        {!selected.locked && (
                            <div className="mt-5 flex justify-end border-t border-line pt-4">
                                <Button size="lg" variant="success" disabled={!changed} busy={save.isPending} onClick={() => { setError(null); save.mutate(); }}>
                                    {t('common.save')}
                                </Button>
                            </div>
                        )}
                    </Card>
                )}
            </div>

            <Modal open={adding} onClose={() => setAdding(false)} title={t('roles.add')} closeLabel={t('common.close')}>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setError(null);
                        create.mutate();
                    }}
                >
                    {error && <Alert>{error}</Alert>}
                    <TextField label={t('roles.name')} value={newName} onChange={setNewName} help={t('roles.name_help')} maxLength={40} required autoFocus />
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setAdding(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" variant="success" busy={create.isPending} disabled={newName.trim().length < 2}>
                            {t('common.save')}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
