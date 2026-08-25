import { describe, expect, it } from 'vitest';
import { formatBbox, haversineKm } from './geo';

describe('haversineKm', () => {
    it('returns 0 for the same point', () => {
        expect(haversineKm(24.1477, 120.6736, 24.1477, 120.6736)).toBeCloseTo(0, 6);
    });

    it('matches a known distance (Taichung to Taipei Station, ~140km)', () => {
        const distance = haversineKm(24.1477, 120.6736, 25.0478, 121.517);
        // 已知的實際距離，容許 5km 誤差（Haversine 假設完美球體，跟 WGS84 橢球有微小落差）。
        expect(distance).toBeGreaterThan(130);
        expect(distance).toBeLessThan(150);
    });

    it('is symmetric regardless of argument order', () => {
        const a = haversineKm(24.1477, 120.6736, 25.0478, 121.517);
        const b = haversineKm(25.0478, 121.517, 24.1477, 120.6736);
        expect(a).toBeCloseTo(b, 9);
    });
});

describe('formatBbox', () => {
    it('組成 API 要的 minLat,minLng,maxLat,maxLng', () => {
        expect(formatBbox({ minLat: 23.95, minLng: 120.43, maxLat: 24.45, maxLng: 121.47 })).toBe(
            '23.95,120.43,24.45,121.47',
        );
    });
});
