import { useQueryClient } from '@tanstack/react-query';
import { UserPlus } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Modal, TextField } from '@/components/ui';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { saleKeys, useCustomers, type Customer } from './api';

type Props = { open: boolean; onClose: () => void; onPick: (customer: Customer) => void };

/** Find a customer by name or mobile, or add one with just a name. */
export function CustomerPicker({ open, onClose, onPick }: Props) {
    const { t } = useTranslation();
    return (
        <Modal open={open} onClose={onClose} title={t('customers.choose')} closeLabel={t('common.close')}>
            {open && <Picker onPick={onPick} />}
        </Modal>
    );
}

function Picker({ onPick }: { onPick: (customer: Customer) => void }) {
    const { t } = useTranslation();
    const [search, setSearch] = useState('');
    const [adding, setAdding] = useState(false);
    const customers = useCustomers(search, false);

    if (adding) return <CustomerForm initialName={search} onSaved={onPick} onCancel={() => setAdding(false)} />;

    return (
        <div className="flex flex-col gap-3">
            <TextField label={t('customers.search')} value={search} onChange={setSearch} autoFocus />
            <ul className="flex max-h-72 flex-col gap-1 overflow-y-auto">
                {customers.data?.data.map((customer) => (
                    <li key={customer.id}>
                        <button
                            type="button"
                            onClick={() => onPick(customer)}
                            className="flex min-h-12 w-full items-center justify-between gap-3 rounded-md border border-line px-3 text-left hover:border-primary hover:bg-primary-soft"
                        >
                            <span>
                                <span className="block font-semibold">{customer.name}</span>
                                <span className="block text-sm text-ink-soft">{[customer.mobile, customer.village].filter(Boolean).join(' · ')}</span>
                            </span>
                            {customer.balance_paise > 0 && <span className="font-semibold text-warning">{formatMoney(customer.balance_paise)}</span>}
                        </button>
                    </li>
                ))}
                {customers.data?.data.length === 0 && <li className="py-2 text-ink-soft">{t('customers.none_found')}</li>}
            </ul>
            <Button variant="secondary" onClick={() => setAdding(true)} icon={<UserPlus className="size-5" aria-hidden />}>
                {t('customers.add')}
            </Button>
        </div>
    );
}

type FormProps = { initialName?: string; customer?: Customer; showLimit?: boolean; onSaved: (customer: Customer) => void; onCancel: () => void };

export function CustomerForm({ initialName = '', customer, showLimit = false, onSaved, onCancel }: FormProps) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const idempotencyKey = useRef(newIdempotencyKey());
    const startsWithDigit = /^\d/.test(initialName);
    const [name, setName] = useState(customer?.name ?? (startsWithDigit ? '' : initialName));
    const [mobile, setMobile] = useState(customer?.mobile ?? (startsWithDigit ? initialName : ''));
    const [village, setVillage] = useState(customer?.village ?? '');
    const [limit, setLimit] = useState(customer && customer.credit_limit_paise > 0 ? String(customer.credit_limit_paise / 100) : '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);

    async function save(confirmDuplicate: boolean) {
        setBusy(true);
        setError(null);
        const body = {
            name: name.trim(),
            mobile: mobile.trim() || null,
            village: village.trim() || null,
            credit_limit_paise: /^\d+$/.test(limit) ? Number(limit) * 100 : 0,
            confirm_duplicate: confirmDuplicate,
        };
        try {
            const saved = customer
                ? await api<Customer>(`/customers/${customer.id}`, { method: 'PUT', body })
                : await api<Customer>('/customers', { method: 'POST', body, idempotencyKey: idempotencyKey.current });
            await queryClient.invalidateQueries({ queryKey: saleKeys.customers });
            onSaved(saved);
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void save(false);
    };
    const field = (key: string) => (error instanceof ApiError ? error.first(key) : undefined);
    const duplicate = error instanceof ApiError && error.code === 'possible_duplicate';
    const duplicates = duplicate ? ((error.payload.duplicates ?? []) as { id: number; name: string; village: string | null }[]) : [];

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {duplicate && (
                <Alert tone="warning">
                    <p>{t('customers.duplicate', { names: duplicates.map((d) => d.name).join(', ') })}</p>
                    <Button className="mt-2" variant="secondary" onClick={() => void save(true)} busy={busy}>
                        {t('customers.save_anyway')}
                    </Button>
                </Alert>
            )}
            {error !== null && !duplicate && !(error instanceof ApiError && error.status === 422) && <Alert>{t('app.error_generic')}</Alert>}
            <TextField label={t('customers.name')} value={name} onChange={setName} error={field('name')} autoFocus required />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label={t('customers.mobile')}
                    value={mobile}
                    onChange={(v) => setMobile(v.replace(/\D/g, '').slice(0, 10))}
                    error={field('mobile')}
                    inputMode="tel"
                    optional={t('common.optional')}
                />
                <TextField label={t('customers.village')} value={village} onChange={setVillage} error={field('village')} optional={t('common.optional')} />
            </div>
            {showLimit && (
                <TextField
                    label={t('customers.limit')}
                    value={limit}
                    onChange={(v) => setLimit(v.replace(/\D/g, ''))}
                    help={t('customers.limit_help')}
                    inputMode="numeric"
                    optional={t('common.optional')}
                />
            )}
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onCancel} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy}>
                    {t('common.save')}
                </Button>
            </div>
        </form>
    );
}
