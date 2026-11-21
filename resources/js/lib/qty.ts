/**
 * Stock is kept as whole base units (tablets, pieces, grams). People read it
 * in packs: 125 tablets of a 10-tablet strip is "12 strips + 5 tablets".
 */
export function splitQty(qtyBase: number, packSize: number): { packs: number; loose: number } {
    const sign = qtyBase < 0 ? -1 : 1;
    const abs = Math.abs(qtyBase);

    return { packs: sign * Math.floor(abs / packSize), loose: sign * (abs % packSize) };
}

/** unitLabel turns a unit code (STRIP) into the word to show (strip / स्ट्रिप). */
export function formatQty(
    qtyBase: number,
    packSize: number,
    baseUnit: string,
    pricingUnit: string,
    unitLabel: (code: string, count?: number) => string = (code) => code,
): string {
    if (packSize <= 1) return `${qtyBase} ${unitLabel(baseUnit, qtyBase)}`;

    const { packs, loose } = splitQty(qtyBase, packSize);
    if (packs === 0) return `${loose} ${unitLabel(baseUnit, loose)}`;
    if (loose === 0) return `${packs} ${unitLabel(pricingUnit, packs)}`;

    return `${packs} ${unitLabel(pricingUnit, packs)} + ${Math.abs(loose)} ${unitLabel(baseUnit, loose)}`;
}

/** "2028-05-31" -> "May 2028" style month for medicine expiry; full date when not a month end. */
export function formatExpiry(isoDate: string | null, locale: string): string {
    if (!isoDate) return '—';
    const [year, month, day] = isoDate.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    const lastDay = new Date(year, month, 0).getDate();

    return date.toLocaleDateString(`${locale}-IN-u-nu-latn`, {
        month: 'short',
        year: 'numeric',
        ...(day === lastDay ? {} : { day: 'numeric' }),
    });
}
