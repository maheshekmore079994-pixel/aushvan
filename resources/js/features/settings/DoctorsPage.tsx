import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Search, Stethoscope } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Button, Card, CheckField, EmptyState, Modal, PageHeader, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import type { Doctor } from '@/features/sales/api';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';

const useAllDoctors = () => useQuery({ queryKey: ['doctors', 'all'], queryFn: () => api<{ data: Doctor[] }>('/doctors?all=1').then((r) => r.data) });

/** The doctors offered on the prescription step: see, add, correct, hide. */
export function DoctorsPage() {
    const { t } = useTranslation();
    const { can } = useSession();
    const doctors = useAllDoctors();
    const [search, setSearch] = useState('');
    const [editing, setEditing] = useState<Doctor | 'new' | null>(null);
    const canManage = can('medicines.manage');
    const words = search.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const shown = (doctors.data ?? []).filter((d) => words.every((w) => [d.name, d.reg_no, d.clinic, d.phone].join(' ').toLowerCase().includes(w)));

    return (
        <div className="flex flex-col gap-4">
            <PageHeader title={t('doctors.title')} subtitle={t('doctors.intro')}>
                {canManage && (
                    <Button onClick={() => setEditing('new')} icon={<Plus className="size-5" aria-hidden />}>
                        {t('doctors.add')}
                    </Button>
                )}
            </PageHeader>

            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-soft" aria-hidden />
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    aria-label={t('doctors.search')}
                    placeholder={t('doctors.search')}
                    className="block min-h-12 w-full rounded-lg border border-line bg-white pl-10 pr-4"
                />
            </div>

            {doctors.isError && <Alert>{t('app.error_generic')}</Alert>}
            {doctors.isPending && <Spinner label={t('common.loading')} />}
            {doctors.data && shown.length === 0 && (
                <EmptyState title={doctors.data.length === 0 ? t('doctors.none_yet') : t('customers.none_found')}>
                    <p>{t('doctors.none_help')}</p>
                </EmptyState>
            )}
            {shown.length > 0 && (
                <Card className="p-0">
                    <ul className="divide-y divide-line">
                        {shown.map((d) => (
                            <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <span className="flex items-center gap-3">
                                    <Stethoscope className="size-5 text-medical" aria-hidden />
                                    <span>
                                        <span className="block font-semibold">
                                            {d.name}
                                            {!d.is_active && (
                                                <span className="ml-2 align-middle">
                                                    <Badge tone="neutral">{t('common.inactive')}</Badge>
                                                </span>
                                            )}
                                        </span>
                                        <span className="block text-sm text-ink-soft">{[d.reg_no, d.clinic, d.address, d.phone].filter(Boolean).join(' · ')}</span>
                                    </span>
                                </span>
                                {canManage && (
                                    <Button variant="secondary" onClick={() => setEditing(d)} icon={<Pencil className="size-4" aria-hidden />}>
                                        {t('common.edit')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <Modal open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? t('doctors.add') : t('common.edit')} closeLabel={t('common.close')}>
                {editing !== null && <DoctorForm doctor={editing === 'new' ? undefined : editing} onDone={() => setEditing(null)} />}
            </Modal>
        </div>
    );
}

function DoctorForm({ doctor, onDone }: { doctor?: Doctor; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const key = useRef(newIdempotencyKey());
    const [form, setForm] = useState({ name: doctor?.name ?? '', reg_no: doctor?.reg_no ?? '', clinic: doctor?.clinic ?? '', address: doctor?.address ?? '', phone: doctor?.phone ?? '', is_active: doctor?.is_active ?? true });
    const set = (k: keyof typeof form) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const field = (k: string) => (error instanceof ApiError ? error.first(k) : undefined);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        const body = { name: form.name.trim(), reg_no: form.reg_no.trim() || null, clinic: form.clinic.trim() || null, address: form.address.trim() || null, phone: form.phone.trim() || null, is_active: form.is_active };
        try {
            if (doctor) await api(`/doctors/${doctor.id}`, { method: 'PUT', body });
            else await api('/doctors', { method: 'POST', body, idempotencyKey: key.current });
            await queryClient.invalidateQueries({ queryKey: ['doctors'] });
            toast('success', t('common.saved'));
            onDone();
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {error !== null && !(error instanceof ApiError && error.status === 422) && <Alert>{t('app.error_generic')}</Alert>}
            <TextField label={t('customers.name')} value={form.name} onChange={set('name')} error={field('name')} autoFocus required />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('doctors.reg_no')} value={form.reg_no} onChange={set('reg_no')} error={field('reg_no')} optional={t('common.optional')} />
                <TextField label={t('customers.mobile')} value={form.phone} onChange={(v) => set('phone')(v.replace(/\D/g, '').slice(0, 10))} error={field('phone')} inputMode="tel" optional={t('common.optional')} />
                <TextField label={t('doctors.clinic')} value={form.clinic} onChange={set('clinic')} error={field('clinic')} optional={t('common.optional')} />
                <TextField label={t('suppliers.address')} value={form.address} onChange={set('address')} error={field('address')} optional={t('common.optional')} />
            </div>
            {doctor && <CheckField label={t('doctors.active')} checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} help={t('doctors.active_help')} />}
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy}>
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}
