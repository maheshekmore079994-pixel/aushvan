import { describe, expect, it } from 'vitest';
import { formatExpiry, formatQty, splitQty } from './qty';

describe('formatQty', () => {
    it('shows packs and loose units', () => {
        expect(formatQty(125, 10, 'TAB', 'STRIP')).toBe('12 STRIP + 5 TAB');
        expect(formatQty(120, 10, 'TAB', 'STRIP')).toBe('12 STRIP');
        expect(formatQty(7, 10, 'TAB', 'STRIP')).toBe('7 TAB');
        expect(formatQty(0, 10, 'TAB', 'STRIP')).toBe('0 TAB');
    });

    it('shows single-unit products plainly', () => {
        expect(formatQty(14, 1, 'BTL', 'BTL')).toBe('14 BTL');
    });

    it('handles weight kept in grams', () => {
        expect(formatQty(50250, 1000, 'G', 'KG')).toBe('50 KG + 250 G');
    });

    it('uses the given unit words', () => {
        const words: Record<string, string> = { STRIP: 'strips', TAB: 'tablets' };
        expect(formatQty(25, 10, 'TAB', 'STRIP', (code) => words[code])).toBe('2 strips + 5 tablets');
    });

    it('splits negative quantities symmetrically', () => {
        expect(splitQty(-25, 10)).toEqual({ packs: -2, loose: -5 });
    });
});

describe('formatExpiry', () => {
    it('shows month and year for a month-end date, as printed on medicine packs', () => {
        expect(formatExpiry('2028-05-31', 'en')).toBe('May 2028');
        expect(formatExpiry('2028-02-29', 'en')).toBe('Feb 2028');
    });

    it('shows the day when the date is not a month end', () => {
        expect(formatExpiry('2028-05-15', 'en')).toBe('15 May 2028');
    });

    it('shows a dash when there is no date', () => {
        expect(formatExpiry(null, 'en')).toBe('—');
    });
});
