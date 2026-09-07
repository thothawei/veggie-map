import { describe, expect, it } from 'vitest';
import { shouldRefreshToken } from './tokenRefresh';

describe('shouldRefreshToken', () => {
    const now = new Date('2026-09-06T12:00:00Z');

    it('永不過期（null）不用排程', () => {
        expect(shouldRefreshToken(null, now)).toBe(false);
    });

    it('還早得很（超過緩衝時間）不用 refresh', () => {
        expect(shouldRefreshToken('2026-09-10T12:00:00Z', now)).toBe(false);
    });

    it('進入緩衝時間內要 refresh', () => {
        expect(shouldRefreshToken('2026-09-06T12:30:00Z', now, 60)).toBe(true);
    });

    it('已經過期也要 refresh（讓呼叫端的失敗處理去接手登出）', () => {
        expect(shouldRefreshToken('2026-09-06T11:00:00Z', now, 60)).toBe(true);
    });

    it('格式壞掉的時間戳不會讓程式炸掉，安全地不排程', () => {
        expect(shouldRefreshToken('not-a-date', now)).toBe(false);
    });
});
