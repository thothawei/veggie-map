import { describe, expect, it } from 'vitest';
import { safeHttpUrl, safeInternalPath } from './redirect';

describe('safeInternalPath', () => {
    it('接受站內路徑與 query', () => {
        expect(safeInternalPath('/favorites')).toBe('/favorites');
        expect(safeInternalPath('/restaurants?city=taichung')).toBe('/restaurants?city=taichung');
    });

    it('拒絕協定相對路徑與外部網址', () => {
        expect(safeInternalPath('//evil.example')).toBe('/');
        expect(safeInternalPath('https://evil.example')).toBe('/');
        expect(safeInternalPath('\\evil')).toBe('/');
        expect(safeInternalPath('')).toBe('/');
        expect(safeInternalPath(undefined)).toBe('/');
    });
});

describe('safeHttpUrl', () => {
    it('只放行 http／https', () => {
        expect(safeHttpUrl('https://example.com/menu')).toBe('https://example.com/menu');
        expect(safeHttpUrl('http://example.com')).toBe('http://example.com/');
    });

    it('拒絕 javascript 與沒有協定的字串', () => {
        expect(safeHttpUrl('javascript:alert(1)')).toBeNull();
        expect(safeHttpUrl('//evil.example')).toBeNull();
        expect(safeHttpUrl('example.com')).toBeNull();
        expect(safeHttpUrl('')).toBeNull();
        expect(safeHttpUrl(undefined)).toBeNull();
    });
});
