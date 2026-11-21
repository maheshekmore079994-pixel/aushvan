import { useQueryClient } from '@tanstack/react-query';
import { Save } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Button, Card, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { api, ApiError, NetworkError } from '@/lib/api';
import { shopKey, useShop, type GstMode, type ShopDetails } from './api';

/** Form state is all strings; empty string means "not given". */
type Form = {
    name: string;
    legal_name: string;
    address: string;
    village: string;
    taluka: string;
    district: string;
    state_code: string;
    pincode: string;
    phone: string;
    gst_mode: GstMode;
    gstin: string;
    drug_licence_20: string;
    drug_licence_21: string;
    drug_licence_20f: string;
    licence_retention_due: string;
    bill_footer: string;
    counter_prefix: string;
};

function toForm(shop: ShopDetails): Form {
    return {
        name: shop.name,
        legal_name: shop.legal_name ?? '',
        address: shop.address ?? '',
        village: shop.village ?? '',
        taluka: shop.taluka ?? '',
        district: shop.district ?? '',
        state_code: shop.state_code,
        pincode: shop.pincode ?? '',
        phone: shop.phone ?? '',
        gst_mode: shop.gst_mode,
        gstin: shop.gstin ?? '',
        drug_licence_20: shop.settings.drug_licence_20 ?? '',
        drug_licence_21: shop.settings.drug_licence_21 ?? '',
        drug_licence_20f: shop.settings.drug_licence_20f ?? '',
        licence_retention_due: shop.settings.licence_retention_due ?? '',
        bill_footer: shop.settings.bill_footer ?? '',
        counter_prefix: shop.settings.counter_prefix ?? '',
    };
}

function toPayload(form: Form) {
    const blank = (value: string) => (value.trim() === '' ? null : value.trim());

    return {
        name: form.name.trim(),
        legal_name: blank(form.legal_name),
        address: blank(form.address),
        village: blank(form.village),
        taluka: blank(form.taluka),
        district: blank(form.district),
        state_code: form.state_code.trim(),
        pincode: blank(form.pincode),
        phone: blank(form.phone),
        gst_mode: form.gst_mode,
        gstin: form.gst_mode === 'unregistered' ? null : blank(form.gstin),
        settings: {
            drug_licence_20: blank(form.drug_licence_20),
            drug_licence_21: blank(form.drug_licence_21),
            drug_licence_20f: blank(form.drug_licence_20f),
            licence_retention_due: blank(form.licence_retention_due),
            bill_footer: blank(form.bill_footer),
            counter_prefix: form.counter_prefix.trim().toUpperCase(),
        },
    };
}

export function ShopSettingsPage() {
    const { t } = useTranslation();
    const { can } = useSession();
    const toast = useToast();
    const queryClient = useQueryClient();
    const shop = useShop();
    const [form, setForm] = useState<Form | null>(null);
    const [error, setError] = useState<unknown>(null);
    const [busy, setBusy] = useState(false);
    const editable = can('settings.manage');

    useEffect(() => {
        if (shop.data && form === null) setForm(toForm(shop.data));
    }, [shop.data, form]);

    if (shop.isError) return <Alert>{t('app.error_generic')}</Alert>;
    if (shop.isPending || form === null) return <Spinner label={t('common.loading')} shape="form" />;

    const set = (key: keyof Form) => (value: string) => setForm((f) => (f ? { ...f, [key]: value } : f));
    const fieldError = (name: string) => (error instanceof ApiError ? error.first(name) : undefined);
    const optional = t('common.optional');

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!form) return;
        setBusy(true);
        setError(null);
        try {
            const saved = await api<ShopDetails>('/store', { method: 'PUT', body: toPayload(form) });
            queryClient.setQueryData(shopKey, saved);
            void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
            setForm(toForm(saved));
            toast('success', t('common.saved'));
        } catch (e) {
            setError(e);
            if (e instanceof NetworkError) toast('error', t('app.no_connection'));
            else if (!(e instanceof ApiError) || e.status !== 422) toast('error', t('app.error_generic'));
        } finally {
            setBusy(false);
        }
    }

    const gstHelp = t(`shop.gst_${form.gst_mode}_help`);

    return (
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{t('shop.title')}</h1>
                {editable && (
                    <Button type="submit" variant="success" busy={busy} icon={<Save className="size-5" aria-hidden />}>
                        {busy ? t('common.saving') : t('common.save')}
                    </Button>
                )}
            </div>

            {!editable && <Alert tone="info">{t('shop.read_only')}</Alert>}
            {error instanceof ApiError && error.status === 403 && <Alert>{t('app.forbidden')}</Alert>}

            <fieldset disabled={!editable || busy} className="flex flex-col gap-4">
                <Card title={t('shop.section_details')}>
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField label={t('shop.name')} value={form.name} onChange={set('name')} error={fieldError('name')} required />
                        <TextField label={t('shop.phone')} value={form.phone} onChange={set('phone')} error={fieldError('phone')} inputMode="tel" />
                        <div className="md:col-span-2">
                            <TextField label={t('shop.address')} value={form.address} onChange={set('address')} error={fieldError('address')} />
                        </div>
                        <TextField label={t('shop.village')} value={form.village} onChange={set('village')} error={fieldError('village')} />
                        <TextField label={t('shop.taluka')} value={form.taluka} onChange={set('taluka')} error={fieldError('taluka')} />
                        <TextField label={t('shop.district')} value={form.district} onChange={set('district')} error={fieldError('district')} />
                        <TextField label={t('shop.pincode')} value={form.pincode} onChange={set('pincode')} error={fieldError('pincode')} inputMode="numeric" maxLength={6} />
                    </div>
                </Card>

                <Card title={t('shop.section_gst')}>
                    <div className="grid gap-4 md:grid-cols-2">
                        <SelectField
                            label={t('shop.gst_mode')}
                            value={form.gst_mode}
                            onChange={(value) => set('gst_mode')(value)}
                            error={fieldError('gst_mode')}
                            help={gstHelp}
                            options={[
                                { value: 'unregistered', label: t('shop.gst_unregistered') },
                                { value: 'composition', label: t('shop.gst_composition') },
                                { value: 'regular', label: t('shop.gst_regular') },
                            ]}
                        />
                        {form.gst_mode !== 'unregistered' && (
                            <>
                                <TextField
                                    label={t('shop.gstin')}
                                    value={form.gstin}
                                    onChange={(value) => set('gstin')(value.toUpperCase())}
                                    error={fieldError('gstin')}
                                    maxLength={15}
                                    autoCapitalize="characters"
                                    required
                                />
                                <TextField label={t('shop.legal_name')} value={form.legal_name} onChange={set('legal_name')} error={fieldError('legal_name')} optional={optional} />
                                <TextField
                                    label={t('shop.state_code')}
                                    value={form.state_code}
                                    onChange={set('state_code')}
                                    error={fieldError('state_code')}
                                    help={t('shop.state_code_help')}
                                    inputMode="numeric"
                                    maxLength={2}
                                />
                            </>
                        )}
                    </div>
                </Card>

                <Card title={t('shop.section_licence')}>
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField label={t('shop.licence_20')} value={form.drug_licence_20} onChange={set('drug_licence_20')} error={fieldError('settings.drug_licence_20')} />
                        <TextField label={t('shop.licence_21')} value={form.drug_licence_21} onChange={set('drug_licence_21')} error={fieldError('settings.drug_licence_21')} />
                        <TextField
                            label={t('shop.licence_20f')}
                            value={form.drug_licence_20f}
                            onChange={set('drug_licence_20f')}
                            error={fieldError('settings.drug_licence_20f')}
                            help={t('shop.licence_20f_help')}
                            optional={optional}
                        />
                        <TextField
                            label={t('shop.licence_due')}
                            type="date"
                            value={form.licence_retention_due}
                            onChange={set('licence_retention_due')}
                            error={fieldError('settings.licence_retention_due')}
                            optional={optional}
                        />
                    </div>
                </Card>

                <Card title={t('shop.section_bill')}>
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField
                            label={t('shop.bill_footer')}
                            value={form.bill_footer}
                            onChange={set('bill_footer')}
                            error={fieldError('settings.bill_footer')}
                            placeholder={t('shop.bill_footer_placeholder')}
                            optional={optional}
                        />
                        <TextField
                            label={t('shop.counter_prefix')}
                            value={form.counter_prefix}
                            onChange={(value) => set('counter_prefix')(value.toUpperCase())}
                            error={fieldError('settings.counter_prefix')}
                            help={t('shop.counter_prefix_help')}
                            maxLength={2}
                            optional={optional}
                        />
                    </div>
                </Card>
            </fieldset>
        </form>
    );
}
