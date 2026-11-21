/** Money is integer paise everywhere. These are the only formatting/parsing helpers. */

/** 12345650 -> "₹1,23,456.50" */
export function formatMoney(paise: number, withSymbol = true): string {
    const negative = paise < 0;
    const abs = Math.abs(Math.trunc(paise));
    let rupees = String(Math.floor(abs / 100));
    const fraction = String(abs % 100).padStart(2, '0');

    if (rupees.length > 3) {
        const last3 = rupees.slice(-3);
        const rest = rupees.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
        rupees = `${rest},${last3}`;
    }

    return `${negative ? '-' : ''}${withSymbol ? '₹' : ''}${rupees}.${fraction}`;
}

/** "350", "350.5", "350.50" -> 35050. Returns null for anything else (never guesses). */
export function parseRupees(input: string): number | null {
    const match = input.trim().match(/^(\d+)(?:\.(\d{1,2}))?$/);
    if (!match) return null;

    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** Short amounts for chart axes and small tiles: 95000 -> "₹950", 12500000 -> "₹1.25L". Never used on bills. */
export function formatCompactRupees(paise: number): string {
    const rupees = Math.abs(paise) / 100;
    const sign = paise < 0 ? '-' : '';
    const short = (value: number, suffix: string) => `${sign}₹${value.toFixed(value >= 100 ? 0 : value >= 10 ? 1 : 2).replace(/\.?0+$/, '')}${suffix}`;

    if (rupees >= 10000000) return short(rupees / 10000000, 'Cr');
    if (rupees >= 100000) return short(rupees / 100000, 'L');
    if (rupees >= 1000) return short(rupees / 1000, 'K');
    return `${sign}₹${Math.round(rupees)}`;
}
