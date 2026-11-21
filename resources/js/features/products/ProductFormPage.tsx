import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ChevronDown, ChevronRight, Plus, Save, X } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ScanButton } from '@/components/CameraScanner';
import { Alert, Button, Card, CheckField, SelectField, Spinner, TextField } from '@/components/ui';
import { useToast } from '@/components/toast';
import { useSession } from '@/features/auth/session';
import { api, ApiError, NetworkError, newIdempotencyKey } from '@/lib/api';
import { parseRupees } from '@/lib/money';
import { productKeys, useCatalogOptions, useProduct, type CatalogOptions, type ProductDetail, type ProductType } from './api';

type Form = {
    type: ProductType;
    name: string;
    name_local: string;
    category_id: string;
    base_unit_id: string;
    pack_unit_id: string;
    pack_size: string;
    allow_loose_sale: boolean;
    mrp: string;
    selling_price: string;
    tax_rate_id: string;
    barcodes: string[];
    generate_barcode: boolean;
    generic_name: string;
    strength: string;
    dosage_form: string;
    drug_schedule_id: string;
    storage_instructions: string;
    hsn_code: string;
    brand: string;
    manufacturer: string;
    rack_location: string;
    min_stock: string;
    tracks_expiry: boolean;
    is_quick_item: boolean;
    is_active: boolean;
};

const rupees = (paise: number) => (paise > 0 ? (paise / 100).toFixed(2) : '');

function blankForm(options: CatalogOptions, type: ProductType, scannedBarcode: string | null = null): Form {
    const unitId = (code: string) => String(options.units.find((u) => u.code === code)?.id ?? '');
    const medical = type === 'medical';

    return {
        type,
        name: '',
        name_local: '',
        category_id: '',
        base_unit_id: unitId(medical ? 'TAB' : 'PCS'),
        pack_unit_id: medical ? unitId('STRIP') : '',
        pack_size: medical ? '10' : '',
        allow_loose_sale: medical,
        mrp: '',
        selling_price: '',
        tax_rate_id: String(options.tax_rates.find((r) => r.rate_percent === '5.00')?.id ?? options.tax_rates[0]?.id ?? ''),
        barcodes: [scannedBarcode ?? ''],
        generate_barcode: false,
        generic_name: '',
        strength: '',
        dosage_form: medical ? 'tablet' : '',
        drug_schedule_id: '',
        storage_instructions: '',
        hsn_code: medical ? '3004' : '',
        brand: '',
        manufacturer: '',
        rack_location: '',
        min_stock: '',
        tracks_expiry: false,
        is_quick_item: false,
        is_active: true,
    };
}

function fromProduct(p: ProductDetail): Form {
    return {
        type: p.type,
        name: p.name,
        name_local: p.name_local ?? '',
        category_id: p.category_id ? String(p.category_id) : '',
        base_unit_id: String(p.base_unit_id),
        pack_unit_id: p.pack_unit_id ? String(p.pack_unit_id) : '',
        pack_size: p.pack_unit_id ? String(p.pack_size) : '',
        allow_loose_sale: p.allow_loose_sale,
        mrp: rupees(p.mrp_paise),
        selling_price: rupees(p.selling_price_paise),
        tax_rate_id: String(p.tax_rate_id),
        barcodes: p.barcodes.length > 0 ? p.barcodes.map((b) => b.barcode) : [''],
        generate_barcode: p.barcodes.some((b) => b.is_internal),
        generic_name: p.medicine?.generic_name ?? '',
        strength: p.medicine?.strength ?? '',
        dosage_form: p.medicine?.dosage_form ?? '',
        drug_schedule_id: p.medicine?.drug_schedule_id ? String(p.medicine.drug_schedule_id) : '',
        storage_instructions: p.medicine?.storage_instructions ?? '',
        hsn_code: p.hsn_code ?? '',
        brand: p.brand ?? '',
        manufacturer: p.manufacturer ?? '',
        rack_location: p.rack_location ?? '',
        min_stock: p.min_stock > 0 ? String(p.min_stock) : '',
        tracks_expiry: p.tracks_expiry,
        is_quick_item: p.is_quick_item,
        is_active: p.is_active,
    };
}

type Duplicate = { id: number; name: string; sku: string };

export function ProductFormPage() {
    const { t } = useTranslation();
    const params = useParams();
    const [search] = useSearchParams();
    const editingId = params.id ? Number(params.id) : 0;
    const options = useCatalogOptions();
    const existing = useProduct(editingId);

    if (options.isError || existing.isError) return <Alert>{t('app.error_generic')}</Alert>;
    if (options.isPending || (editingId > 0 && existing.isPending)) return <Spinner label={t('common.loading')} shape="form" />;



    return (
        <ProductForm
            key={editingId}
            options={options.data}
            product={editingId > 0 ? (existing.data ?? null) : null}
            initial={editingId > 0 && existing.data ? fromProduct(existing.data) : blankForm(options.data, 'medical', search.get('barcode'))}
        />
    );
}

function ProductForm({ options, product, initial }: { options: CatalogOptions; product: ProductDetail | null; initial: Form }) {
    const { t, i18n } = useTranslation();
    const [search] = useSearchParams();
    const { can } = useSession();
    const navigate = useNavigate();
    const toast = useToast();
    const queryClient = useQueryClient();
    const idempotencyKey = useRef(newIdempotencyKey());
    const [form, setForm] = useState<Form>(initial);
    const [more, setMore] = useState(product !== null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [duplicates, setDuplicates] = useState<Duplicate[]>([]);
    const [similar, setSimilar] = useState<Duplicate[]>([]);

    const set = <K extends keyof Form>(key: K) => (value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));
    const medical = form.type === 'medical';
    const locked = product?.has_stock_history ?? false;
    const canGeneral = can('products.manage');
    const local_ = i18n.language === 'mr';
    const unitName = (u: CatalogOptions['units'][number]) => (local_ && u.name_local ? u.name_local : u.name);
    const unitOptions = options.units.map((u) => ({ value: String(u.id), label: unitName(u) }));
    const baseUnit = options.units.find((u) => String(u.id) === form.base_unit_id);
    const packUnit = options.units.find((u) => String(u.id) === form.pack_unit_id);
    const priceUnit = packUnit ?? baseUnit;
    const schedule = options.drug_schedules.find((s) => String(s.id) === form.drug_schedule_id);

    // Show products with a similar name while typing, so a duplicate is noticed before saving.
    useEffect(() => {
        const name = form.name.trim();
        if (name.length < 3) {
            setSimilar([]);
            return;
        }
        const timer = setTimeout(() => {
            api<{ data: Duplicate[] }>(`/products/similar?name=${encodeURIComponent(name)}${product ? `&ignore=${product.id}` : ''}`)
                .then((r) => setSimilar(r.data))
                .catch(() => setSimilar([]));
        }, 400);
        return () => clearTimeout(timer);
    }, [form.name, product]);

    function switchType(type: ProductType) {
        if (type === form.type) return;
        const blank = blankForm(options, type);
        // Keep what was typed; take the unit and tax defaults of the new type.
        setForm((f) => ({
            ...blank,
            name: f.name,
            name_local: f.name_local,
            mrp: f.mrp,
            selling_price: f.selling_price,
            barcodes: f.barcodes,
            brand: f.brand,
            manufacturer: f.manufacturer,
        }));
    }

    async function save(confirmDuplicate: boolean) {
        const problems: Record<string, string> = {};
        const mrp = form.mrp.trim() === '' ? 0 : parseRupees(form.mrp);
        const selling = parseRupees(form.selling_price.trim() === '' ? form.mrp : form.selling_price);

        if (form.name.trim() === '') problems.name = t('validation.required');
        if (mrp === null) problems.mrp = t('validation.amount');
        if (selling === null || selling < 1) problems.selling_price = t('validation.amount');
        if (mrp && selling && mrp > 0 && selling > mrp) problems.selling_price = t('products.above_mrp');
        if (form.pack_unit_id && !/^\d+$/.test(form.pack_size)) problems.pack_size = t('validation.whole_number');
        if (form.min_stock !== '' && !/^\d+$/.test(form.min_stock)) problems.min_stock = t('validation.whole_number');
        setLocal(problems);
        if (Object.keys(problems).length > 0) {
            if (problems.min_stock) setMore(true);
            return;
        }

        const blank = (value: string) => (value.trim() === '' ? null : value.trim());
        const body = {
            type: form.type,
            name: form.name.trim(),
            name_local: blank(form.name_local),
            category_id: form.category_id ? Number(form.category_id) : null,
            base_unit_id: Number(form.base_unit_id),
            pack_unit_id: form.pack_unit_id ? Number(form.pack_unit_id) : null,
            pack_size: form.pack_unit_id ? Number(form.pack_size) : null,
            allow_loose_sale: form.allow_loose_sale,
            mrp_paise: mrp,
            selling_price_paise: selling,
            tax_rate_id: Number(form.tax_rate_id),
            barcodes: form.barcodes.map((b) => b.trim()).filter(Boolean).map((barcode) => ({ barcode, unit: product?.barcodes.find((b) => b.barcode === barcode)?.unit ?? 'pack' })),
            generate_barcode: form.generate_barcode,
            hsn_code: blank(form.hsn_code),
            brand: blank(form.brand),
            manufacturer: blank(form.manufacturer),
            rack_location: blank(form.rack_location),
            min_stock: form.min_stock === '' ? 0 : Number(form.min_stock),
            tracks_expiry: form.tracks_expiry,
            is_quick_item: form.is_quick_item,
            is_active: form.is_active,
            confirm_duplicate: confirmDuplicate,
            version: product?.version,
            medicine: medical
                ? {
                      generic_name: blank(form.generic_name),
                      composition_text: blank([form.generic_name, form.strength].join(' ')),
                      strength: blank(form.strength),
                      dosage_form: blank(form.dosage_form),
                      drug_schedule_id: form.drug_schedule_id ? Number(form.drug_schedule_id) : null,
                      storage_instructions: blank(form.storage_instructions),
                  }
                : null,
        };

        setBusy(true);
        setError(null);
        setDuplicates([]);
        try {
            const saved = product
                ? await api<ProductDetail>(`/products/${product.id}`, { method: 'PUT', body })
                : await api<ProductDetail>('/products', { method: 'POST', body, idempotencyKey: idempotencyKey.current });
            await queryClient.invalidateQueries({ queryKey: productKeys.all });
            await queryClient.invalidateQueries({ queryKey: productKeys.options });
            toast('success', t('common.saved'));
            // A product made from an unknown scan goes straight on to its opening stock, then back to the sale.
            const then = search.get('then');
            navigate(product ? `/products/${saved.id}` : `/products/${saved.id}?stock=1${then ? `&then=${encodeURIComponent(then)}` : ''}`);
        } catch (e) {
            if (e instanceof ApiError && e.code === 'possible_duplicate') {
                setDuplicates((e.payload.duplicates ?? []) as Duplicate[]);
            }
            setError(e);
            if (e instanceof ApiError && e.status === 422 && ['hsn_code', 'min_stock', 'rack_location'].some((f) => e.first(f))) setMore(true);
            window.scrollTo({ top: 0 });
        } finally {
            setBusy(false);
        }
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void save(false);
    };

    const fieldError = (name: string, serverName = name) => local[name] ?? (error instanceof ApiError ? error.first(serverName) : undefined);
    const isDuplicate = error instanceof ApiError && error.code === 'possible_duplicate';

    let message: string | null = null;
    if (error instanceof NetworkError) message = t('app.no_connection_help');
    else if (error instanceof ApiError && error.status === 403) message = t('app.forbidden');
    else if (error instanceof ApiError && error.status === 409 && !isDuplicate) message = t(`errors.${error.code}`, { defaultValue: error.message });
    else if (error instanceof ApiError && error.status === 422) message = t('validation.check_fields');
    else if (error && !isDuplicate) message = t('app.error_generic');

    const categories = options.categories.filter((c) => c.scope === 'both' || c.scope === form.type);
    const typeButton = (type: ProductType, colour: string) => (
        <button
            type="button"
            disabled={locked || (type === 'general' && !canGeneral)}
            aria-pressed={form.type === type}
            onClick={() => switchType(type)}
            className={`min-h-12 flex-1 rounded-md border-2 px-4 text-lg font-bold disabled:cursor-not-allowed disabled:opacity-50 ${
                form.type === type ? `${colour} border-transparent text-white` : 'border-line bg-white text-ink-soft'
            }`}
        >
            {t(`products.type.${type}`)}
        </button>
    );

    return (
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
            <Link to={product ? `/products/${product.id}` : '/products'} className="inline-flex items-center gap-1 font-medium text-primary-dark hover:underline">
                <ArrowLeft className="size-4" aria-hidden />
                {product ? product.name : t('nav.products')}
            </Link>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">{product ? t('products.edit') : t('products.add')}</h1>
                <Button type="submit" variant="success" busy={busy} icon={<Save className="size-5" aria-hidden />}>
                    {busy ? t('common.saving') : t('common.save')}
                </Button>
            </div>

            {message && <Alert>{message}</Alert>}
            {isDuplicate && (
                <Alert tone="warning">
                    <p>{t('products.duplicate_found')}</p>
                    <ul className="my-2 list-disc pl-6">
                        {duplicates.map((d) => (
                            <li key={d.id}>
                                <Link to={`/products/${d.id}`} className="underline">
                                    {d.name}
                                </Link>{' '}
                                ({d.sku})
                            </li>
                        ))}
                    </ul>
                    <Button variant="secondary" onClick={() => void save(true)} busy={busy}>
                        {t('products.save_anyway')}
                    </Button>
                </Alert>
            )}

            <Card>
                <div className="flex gap-3">
                    {typeButton('medical', 'bg-medical')}
                    {typeButton('general', 'bg-general')}
                </div>
                {locked && <p className="mt-2 text-sm text-ink-soft">{t('products.locked_help')}</p>}

                <div className="mt-5 grid gap-4 md:grid-cols-2">
                    <div>
                        <TextField label={t('products.name')} value={form.name} onChange={set('name')} error={fieldError('name')} autoFocus required />
                        {similar.length > 0 && !isDuplicate && (
                            <p className="mt-1 text-sm text-warning">
                                {t('products.similar')}{' '}
                                {similar.map((s, index) => (
                                    <span key={s.id}>
                                        {index > 0 && ', '}
                                        <Link to={`/products/${s.id}`} className="font-semibold underline">
                                            {s.name}
                                        </Link>
                                    </span>
                                ))}
                            </p>
                        )}
                    </div>
                    <TextField label={t('products.name_local')} value={form.name_local} onChange={set('name_local')} error={fieldError('name_local')} optional={t('common.optional')} lang="mr" />
                </div>

                {medical && (
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <TextField label={t('products.generic')} value={form.generic_name} onChange={set('generic_name')} error={fieldError('generic_name', 'medicine.generic_name')} help={t('products.generic_help')} />
                        <TextField label={t('products.strength')} value={form.strength} onChange={set('strength')} error={fieldError('strength', 'medicine.strength')} placeholder="500 mg" />
                        <SelectField
                            label={t('products.form')}
                            value={form.dosage_form}
                            onChange={set('dosage_form')}
                            options={[{ value: '', label: '—' }, ...options.dosage_forms.map((f) => ({ value: f, label: t(`products.dosage.${f}`, { defaultValue: f }) }))]}
                        />
                        <SelectField
                            label={t('products.schedule')}
                            value={form.drug_schedule_id}
                            onChange={set('drug_schedule_id')}
                            error={fieldError('drug_schedule_id', 'medicine.drug_schedule_id')}
                            help={schedule?.warning_text ?? t('products.schedule_help')}
                            options={[{ value: '', label: t('products.schedule_choose') }, ...options.drug_schedules.map((s) => ({ value: String(s.id), label: s.name }))]}
                        />
                    </div>
                )}
            </Card>

            <Card title={t('products.section_units')}>
                <fieldset disabled={locked} className="grid gap-4 md:grid-cols-3">
                    <SelectField label={t('products.base_unit')} value={form.base_unit_id} onChange={set('base_unit_id')} error={fieldError('base_unit_id')} help={t('products.base_unit_help')} options={unitOptions} />
                    <SelectField
                        label={t('products.pack_unit')}
                        value={form.pack_unit_id}
                        onChange={set('pack_unit_id')}
                        error={fieldError('pack_unit_id')}
                        options={[{ value: '', label: t('products.no_pack') }, ...unitOptions.filter((u) => u.value !== form.base_unit_id)]}
                    />
                    {packUnit && baseUnit && (
                        <TextField
                            label={t('products.pack_size', { pack: unitName(packUnit), base: unitName(baseUnit) })}
                            value={form.pack_size}
                            onChange={(v) => set('pack_size')(v.replace(/\D/g, ''))}
                            error={fieldError('pack_size')}
                            inputMode="numeric"
                        />
                    )}
                </fieldset>
                {packUnit && baseUnit && (
                    <div className="mt-4">
                        <CheckField
                            label={t('products.allow_loose', { base: unitName(baseUnit) })}
                            help={medical ? t('products.allow_loose_medical_help') : undefined}
                            checked={form.allow_loose_sale}
                            onChange={set('allow_loose_sale')}
                        />
                    </div>
                )}
            </Card>

            <Card title={t('products.section_price')}>
                <div className="grid gap-4 md:grid-cols-3">
                    <TextField
                        label={t('products.mrp_per', { unit: priceUnit ? unitName(priceUnit) : '' })}
                        value={form.mrp}
                        onChange={set('mrp')}
                        error={fieldError('mrp', 'mrp_paise')}
                        help={t('products.mrp_help')}
                        inputMode="decimal"
                    />
                    <TextField
                        label={t('products.selling_per', { unit: priceUnit ? unitName(priceUnit) : '' })}
                        value={form.selling_price}
                        onChange={set('selling_price')}
                        error={fieldError('selling_price', 'selling_price_paise')}
                        help={t('products.selling_help')}
                        inputMode="decimal"
                    />
                    <SelectField
                        label="GST"
                        value={form.tax_rate_id}
                        onChange={set('tax_rate_id')}
                        error={fieldError('tax_rate_id')}
                        help={t('products.gst_help')}
                        options={options.tax_rates.map((r) => ({ value: String(r.id), label: r.name }))}
                    />
                </div>
            </Card>

            <Card title={t('products.section_barcode')}>
                <p className="-mt-2 mb-3 text-ink-soft">{t('products.barcode_help')}</p>
                <div className="flex flex-col gap-2">
                    {form.barcodes.map((barcode, index) => (
                        <div key={index} className="flex items-start gap-2">
                            <div className="flex-1">
                                <TextField
                                    label={t('products.barcode_n', { n: index + 1 })}
                                    value={barcode}
                                    onChange={(value) => set('barcodes')(form.barcodes.map((b, i) => (i === index ? value : b)))}
                                    error={fieldError(`barcodes.${index}.barcode`)}
                                    // A scanner types the code and presses Enter; that must not save the form.
                                    onKeyDown={(e) => e.key === 'Enter' && e.preventDefault()}
                                />
                            </div>
                            <ScanButton
                                className="mt-6 size-10 border border-line bg-white"
                                onCode={(code) => set('barcodes')(form.barcodes.map((b, i) => (i === index ? code : b)))}
                            />
                            {form.barcodes.length > 1 && (
                                <Button
                                    variant="ghost"
                                    className="mt-7"
                                    aria-label={t('products.remove_barcode')}
                                    onClick={() => set('barcodes')(form.barcodes.filter((_, i) => i !== index))}
                                    icon={<X className="size-5" aria-hidden />}
                                />
                            )}
                        </div>
                    ))}
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <Button variant="secondary" onClick={() => set('barcodes')([...form.barcodes, ''])} icon={<Plus className="size-4" aria-hidden />}>
                        {t('products.add_barcode')}
                    </Button>
                    <div className="min-w-64 flex-1">
                        <CheckField label={t('products.generate_barcode')} checked={form.generate_barcode} onChange={set('generate_barcode')} />
                    </div>
                </div>
            </Card>

            <Card>
                <button type="button" className="flex min-h-11 w-full items-center gap-2 text-left text-lg font-semibold" aria-expanded={more} onClick={() => setMore(!more)}>
                    {more ? <ChevronDown className="size-5" aria-hidden /> : <ChevronRight className="size-5" aria-hidden />}
                    {t('products.more_details')}
                </button>
                {more && (
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <SelectField
                            label={t('products.category')}
                            value={form.category_id}
                            onChange={set('category_id')}
                            error={fieldError('category_id')}
                            options={[{ value: '', label: '—' }, ...categories.map((c) => ({ value: String(c.id), label: local_ && c.name_local ? c.name_local : c.name }))]}
                        />
                        <TextField label={t('products.hsn')} value={form.hsn_code} onChange={(v) => set('hsn_code')(v.replace(/\D/g, ''))} error={fieldError('hsn_code')} inputMode="numeric" maxLength={8} />
                        <TextField label={t('products.brand')} value={form.brand} onChange={set('brand')} error={fieldError('brand')} />
                        <TextField label={t('products.manufacturer')} value={form.manufacturer} onChange={set('manufacturer')} error={fieldError('manufacturer')} list="manufacturers" />
                        <datalist id="manufacturers">
                            {options.manufacturers.map((m) => (
                                <option key={m} value={m} />
                            ))}
                        </datalist>
                        <TextField label={t('products.rack')} value={form.rack_location} onChange={set('rack_location')} error={fieldError('rack_location')} />
                        <TextField
                            label={t('products.min_stock_in', { unit: priceUnit ? unitName(priceUnit) : '' })}
                            value={form.min_stock}
                            onChange={(v) => set('min_stock')(v.replace(/\D/g, ''))}
                            error={fieldError('min_stock')}
                            help={t('products.min_stock_help')}
                            inputMode="numeric"
                        />
                        {medical && (
                            <div className="md:col-span-2">
                                <TextField label={t('products.storage')} value={form.storage_instructions} onChange={set('storage_instructions')} placeholder={t('products.storage_placeholder')} />
                            </div>
                        )}
                        {!medical && <CheckField label={t('products.tracks_expiry')} help={t('products.tracks_expiry_help')} checked={form.tracks_expiry} onChange={set('tracks_expiry')} />}
                        <CheckField label={t('products.quick_item')} help={t('products.quick_item_help')} checked={form.is_quick_item} onChange={set('is_quick_item')} />
                        {product && <CheckField label={t('products.active')} help={t('products.active_help')} checked={form.is_active} onChange={set('is_active')} />}
                    </div>
                )}
            </Card>

            <div className="flex justify-end gap-3">
                <Button variant="secondary" onClick={() => navigate(product ? `/products/${product.id}` : '/products')} disabled={busy}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" variant="success" size="lg" busy={busy} icon={<Save className="size-5" aria-hidden />}>
                    {busy ? t('common.saving') : t('common.save')}
                </Button>
            </div>
        </form>
    );
}
