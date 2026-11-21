import { useQueryClient } from '@tanstack/react-query';
import { useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Modal, SelectField, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { parseRupees } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import { productKeys, useUnitLabel, type Batch } from './api';

type ProductUnits = { id: number; name: string; base_unit: string; pricing_unit: string; pack_size: number };

/** Business-rule refusals (HTTP 409) have a code the screens translate; anything else is generic. */
function useErrorMessage() {
    const { t } = useTranslation();
    return (error: unknown): string | null => {
        if (!error) return null;
        if (error instanceof NetworkError) return t('app.no_connection_help');
        if (error instanceof ApiError) {
            if (error.status === 422) return null; // shown beside the fields
            if (error.status === 403) return t('app.forbidden');
            if (error.code) return t(`errors.${error.code}`, { defaultValue: error.message });
        }
        return t('app.error_generic');
    };
}

type AddStockProps = {
    product: ProductUnits & { tracks_batch: boolean; tracks_expiry: boolean; mrp_paise: number; selling_price_paise: number };
    open: boolean;
    onClose: () => void;
};

/** Stock already on the shelf, entered as a batch with its cost. */
export function AddStockModal({ product, open, onClose }: AddStockProps) {
    const { t } = useTranslation();
    return (
        <Modal open={open} onClose={onClose} title={t('stock.add_title', { name: product.name })} closeLabel={t('common.close')}>
            {open && <AddStockForm product={product} onDone={onClose} />}
        </Modal>
    );
}

function AddStockForm({ product, onDone }: { product: AddStockProps['product']; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const unit = useUnitLabel();
    const errorMessage = useErrorMessage();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [form, setForm] = useState({
        batch_no: '',
        expiry_month: '',
        qty: '',
        unit: 'pack',
        cost: '',
        mrp: product.mrp_paise > 0 ? (product.mrp_paise / 100).toFixed(2) : '',
    });
    const set = (key: keyof typeof form) => (value: string) => setForm((f) => ({ ...f, [key]: value }));
    const hasPack = product.pack_size > 1;

    async function submit(event: FormEvent) {
        event.preventDefault();
        const problems: Record<string, string> = {};
        const qty = /^\d+$/.test(form.qty) ? Number(form.qty) : 0;
        const cost = parseRupees(form.cost);
        const mrp = form.mrp.trim() === '' ? null : parseRupees(form.mrp);

        if (qty < 1) problems.qty = t('validation.whole_number');
        if (cost === null) problems.cost = t('validation.amount');
        if (form.mrp.trim() !== '' && mrp === null) problems.mrp = t('validation.amount');
        if (product.tracks_batch && form.batch_no.trim() === '') problems.batch_no = t('validation.required');
        if (product.tracks_expiry && form.expiry_month === '') problems.expiry_month = t('validation.required');
        setLocal(problems);
        if (Object.keys(problems).length > 0) return;

        setBusy(true);
        setError(null);
        try {
            await api(`/products/${product.id}/opening-stock`, {
                method: 'POST',
                idempotencyKey: idempotencyKey.current,
                body: {
                    items: [
                        {
                            qty,
                            unit: hasPack ? form.unit : 'pack',
                            cost_paise: cost,
                            mrp_paise: mrp,
                            batch_no: form.batch_no.trim() || null,
                            expiry_month: form.expiry_month || null,
                        },
                    ],
                },
            });
            await queryClient.invalidateQueries({ queryKey: productKeys.all });
            toast('success', t('stock.added'));
            onDone();
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const message = errorMessage(error);
    const serverField = (name: string) => (error instanceof ApiError ? error.first(`items.0.${name}`) : undefined);

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <div className="grid gap-4 sm:grid-cols-2">
                {(product.tracks_batch || product.tracks_expiry) && (
                    <>
                        <TextField
                            label={t('stock.batch_no')}
                            value={form.batch_no}
                            onChange={(v) => set('batch_no')(v.toUpperCase())}
                            error={local.batch_no ?? serverField('batch_no')}
                            optional={product.tracks_batch ? undefined : t('common.optional')}
                            autoFocus
                        />
                        <TextField
                            label={t('stock.expiry_label')}
                            type="month"
                            value={form.expiry_month}
                            onChange={set('expiry_month')}
                            error={local.expiry_month ?? serverField('expiry_month')}
                            help={t('stock.expiry_help')}
                        />
                    </>
                )}
                <TextField
                    label={t('stock.quantity')}
                    value={form.qty}
                    onChange={(v) => set('qty')(v.replace(/\D/g, ''))}
                    error={local.qty ?? serverField('qty')}
                    inputMode="numeric"
                    autoFocus={!product.tracks_batch && !product.tracks_expiry}
                />
                {hasPack ? (
                    <SelectField
                        label={t('stock.unit')}
                        value={form.unit}
                        onChange={set('unit')}
                        options={[
                            { value: 'pack', label: `${unit(product.pricing_unit)} (${product.pack_size} ${unit(product.base_unit)})` },
                            { value: 'base', label: unit(product.base_unit) },
                        ]}
                    />
                ) : (
                    <div className="self-end pb-2 text-lg font-medium">{unit(product.base_unit)}</div>
                )}
                <TextField
                    label={t('stock.cost_per', { unit: unit(product.pricing_unit, 1) })}
                    value={form.cost}
                    onChange={set('cost')}
                    error={local.cost ?? serverField('cost_paise')}
                    help={t('stock.cost_help')}
                    inputMode="decimal"
                />
                <TextField
                    label={t('stock.mrp_per', { unit: unit(product.pricing_unit, 1) })}
                    value={form.mrp}
                    onChange={set('mrp')}
                    error={local.mrp ?? serverField('mrp_paise')}
                    inputMode="decimal"
                    optional={t('common.optional')}
                />
            </div>
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" busy={busy}>
                    {busy ? t('common.saving') : t('stock.add_button')}
                </Button>
            </div>
        </form>
    );
}

export type AdjustAction = 'mark_unsellable' | 'write_off_damage' | 'write_off_expiry' | 'count_correction';

type AdjustProps = {
    product: ProductUnits;
    batch: Batch | null;
    /** Action chosen when the dialog opens (the Expiry screen opens it on a specific one). */
    initialAction?: AdjustAction;
    onClose: () => void;
};

/** Corrections to one batch. A reason is always required and everything is logged. */
export function AdjustStockModal({ product, batch, initialAction, onClose }: AdjustProps) {
    const { t } = useTranslation();
    return (
        <Modal open={batch !== null} onClose={onClose} title={t('stock.adjust_title', { name: product.name })} closeLabel={t('common.close')}>
            {batch && <AdjustForm key={batch.id} product={product} batch={batch} initialAction={initialAction} onDone={onClose} />}
        </Modal>
    );
}

function AdjustForm({ product, batch, initialAction, onDone }: { product: ProductUnits; batch: Batch; initialAction?: AdjustAction; onDone: () => void }) {
    const { t } = useTranslation();
    const toast = useToast();
    const queryClient = useQueryClient();
    const unit = useUnitLabel();
    const errorMessage = useErrorMessage();
    const idempotencyKey = useRef(newIdempotencyKey());
    const expired = batch.expiry_status === 'expired';
    const [action, setAction] = useState<AdjustAction>(initialAction ?? (expired ? 'mark_unsellable' : 'write_off_damage'));
    const [bucket, setBucket] = useState<'sellable' | 'unsellable'>(batch.qty_sellable > 0 ? 'sellable' : 'unsellable');
    const [qty, setQty] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [local, setLocal] = useState<Record<string, string>>({});

    const show = (qtyBase: number) => formatQty(qtyBase, product.pack_size, product.base_unit, product.pricing_unit, unit);
    const isWriteOff = action === 'write_off_damage' || action === 'write_off_expiry';
    const available = action === 'mark_unsellable' ? batch.qty_sellable : bucket === 'sellable' ? batch.qty_sellable : batch.qty_unsellable;

    async function submit(event: FormEvent) {
        event.preventDefault();
        const problems: Record<string, string> = {};
        const amount = /^\d+$/.test(qty) ? Number(qty) : -1;

        if (amount < 0 || (action !== 'count_correction' && amount < 1)) problems.qty = t('validation.whole_number');
        else if (action !== 'count_correction' && amount > available) problems.qty = t('stock.only_available', { qty: show(available) });
        if (note.trim().length < 3) problems.note = t('validation.reason');
        setLocal(problems);
        if (Object.keys(problems).length > 0) return;

        setBusy(true);
        setError(null);
        try {
            await api(`/stock/batches/${batch.id}/adjust`, {
                method: 'POST',
                idempotencyKey: idempotencyKey.current,
                body: { action, qty: amount, bucket: isWriteOff ? bucket : 'sellable', note: note.trim() },
            });
            await queryClient.invalidateQueries({ queryKey: productKeys.all });
            toast('success', t('stock.adjusted'));
            onDone();
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const message = errorMessage(error);
    const actions: AdjustAction[] = ['mark_unsellable', 'write_off_damage', 'write_off_expiry', 'count_correction'];

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            {message && <Alert>{message}</Alert>}
            <div className="rounded-md bg-surface px-3 py-2">
                <p className="font-semibold">
                    {t('stock.batch_no')}: {batch.batch_no ?? '—'}
                </p>
                <p className="text-ink-soft">
                    {t('stock.for_sale')}: <strong className="text-ink">{show(batch.qty_sellable)}</strong> · {t('stock.not_for_sale')}:{' '}
                    <strong className="text-ink">{show(batch.qty_unsellable)}</strong>
                </p>
            </div>

            <SelectField
                label={t('stock.what_happened')}
                value={action}
                onChange={(value) => setAction(value as AdjustAction)}
                help={t(`stock.action_help.${action}`)}
                options={actions.map((a) => ({ value: a, label: t(`stock.action.${a}`) }))}
            />

            <div className="grid gap-4 sm:grid-cols-2">
                {isWriteOff && batch.qty_unsellable > 0 && batch.qty_sellable > 0 && (
                    <SelectField
                        label={t('stock.take_from')}
                        value={bucket}
                        onChange={(value) => setBucket(value as 'sellable' | 'unsellable')}
                        options={[
                            { value: 'sellable', label: t('stock.for_sale') },
                            { value: 'unsellable', label: t('stock.not_for_sale') },
                        ]}
                    />
                )}
                <TextField
                    label={action === 'count_correction' ? t('stock.counted_qty', { unit: unit(product.base_unit) }) : t('stock.quantity_in', { unit: unit(product.base_unit) })}
                    value={qty}
                    onChange={(v) => setQty(v.replace(/\D/g, ''))}
                    error={local.qty ?? (error instanceof ApiError ? error.first('qty') : undefined)}
                    inputMode="numeric"
                    autoFocus
                />
            </div>
            <TextField
                label={t('stock.reason')}
                value={note}
                onChange={setNote}
                error={local.note ?? (error instanceof ApiError ? error.first('note') : undefined)}
                placeholder={t('stock.reason_placeholder')}
                maxLength={500}
            />
            <div className="flex justify-end gap-3 border-t border-line pt-4">
                <Button variant="secondary" onClick={onDone} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant={isWriteOff ? 'danger' : 'primary'} busy={busy}>
                    {busy ? t('common.saving') : t(`stock.action.${action}`)}
                </Button>
            </div>
        </form>
    );
}
