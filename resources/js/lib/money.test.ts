import { describe, expect, it } from 'vitest';
import { formatMoney, parseRupees } from './money';

describe('formatMoney', () => {
    it('uses Indian digit grouping', () => {
        expect(formatMoney(5)).toBe('₹0.05');
        expect(formatMoney(35000)).toBe('₹350.00');
        expect(formatMoney(123450)).toBe('₹1,234.50');
        expect(formatMoney(12345600)).toBe('₹1,23,456.00');
        expect(formatMoney(12345678901)).toBe('₹12,34,56,789.01');
    });

    it('handles negatives and no symbol', () => {
        expect(formatMoney(-10000000)).toBe('-₹1,00,000.00');
        expect(formatMoney(4200, false)).toBe('42.00');
    });
});

describe('parseRupees', () => {
    it('reads rupees into paise exactly', () => {
        expect(parseRupees('350')).toBe(35000);
        expect(parseRupees('350.5')).toBe(35050);
        expect(parseRupees('350.50')).toBe(35050);
        expect(parseRupees(' 0.29 ')).toBe(29);
        expect(parseRupees('19.99')).toBe(1999);
    });

    it('refuses anything it cannot read exactly', () => {
        expect(parseRupees('')).toBeNull();
        expect(parseRupees('10.005')).toBeNull();
        expect(parseRupees('-5')).toBeNull();
        expect(parseRupees('1,000')).toBeNull();
        expect(parseRupees('ten')).toBeNull();
    });
});
