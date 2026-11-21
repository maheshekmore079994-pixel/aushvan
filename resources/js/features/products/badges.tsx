import { AlertTriangle, CheckCircle2, Clock, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui';
import type { ExpiryStatus, ProductType, StockStatus } from './api';

/** The same badges are used on every product and stock screen. Colour always comes with a word and an icon. */

export function TypeBadge({ type }: { type: ProductType }) {
    const { t } = useTranslation();
    return (
        <span
            className={`inline-flex rounded px-1.5 py-0.5 text-xs font-bold uppercase text-white ${type === 'medical' ? 'bg-medical' : 'bg-general'}`}
        >
            {t(`products.type_short.${type}`)}
        </span>
    );
}

export function StockBadge({ status }: { status: StockStatus }) {
    const { t } = useTranslation();
    if (status === 'out')
        return (
            <Badge tone="danger">
                <XCircle className="size-4" aria-hidden />
                {t('stock.status.out')}
            </Badge>
        );
    if (status === 'low')
        return (
            <Badge tone="warning">
                <AlertTriangle className="size-4" aria-hidden />
                {t('stock.status.low')}
            </Badge>
        );
    return (
        <Badge tone="success">
            <CheckCircle2 className="size-4" aria-hidden />
            {t('stock.status.ok')}
        </Badge>
    );
}

export function ExpiryBadge({ status, days }: { status: ExpiryStatus; days: number | null }) {
    const { t } = useTranslation();
    if (status === 'none' || status === 'ok' || days === null) return null;
    if (status === 'expired')
        return (
            <Badge tone="danger">
                <XCircle className="size-4" aria-hidden />
                {t('stock.expiry.expired')}
            </Badge>
        );
    return (
        <Badge tone="warning">
            <Clock className="size-4" aria-hidden />
            {t('stock.expiry.days_left', { count: days })}
        </Badge>
    );
}

export function ScheduleBadge({ code, isSet }: { code: string | null; isSet: boolean }) {
    const { t } = useTranslation();
    if (!isSet) return <Badge tone="warning">{t('products.schedule_not_set')}</Badge>;
    if (!code || code === 'NONE') return null;
    return <Badge tone="danger">{t('products.schedule_code', { code })}</Badge>;
}
