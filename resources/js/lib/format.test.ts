import { describe, expect, it } from 'vitest';
import { formatDistance, formatRating } from './format';

describe('formatDistance', () => {
    it('一公里以內用公尺，取整到十位', () => {
        // 後端實測回傳 389.4／507.8／538.7 這種小數，GPS 本身就有誤差，
        // 顯示「389.4 公尺」是假精確。
        expect(formatDistance(389.4)).toBe('390 公尺');
        expect(formatDistance(507.8)).toBe('510 公尺');
        expect(formatDistance(12)).toBe('10 公尺');
    });

    it('一公里以上改用公里，一位小數', () => {
        expect(formatDistance(1000)).toBe('1.0 公里');
        expect(formatDistance(2540)).toBe('2.5 公里');
    });

    it('沒有距離就回 null，讓呼叫端決定不要顯示', () => {
        // distance_meters 只在帶座標查詢時才有，列表頁用 bbox 查就沒有這個欄位。
        expect(formatDistance(undefined)).toBeNull();
        expect(formatDistance(null)).toBeNull();
    });

    it('不合理的值不顯示，而不是印出 NaN 公尺', () => {
        expect(formatDistance(Number.NaN)).toBeNull();
        expect(formatDistance(Number.POSITIVE_INFINITY)).toBeNull();
        expect(formatDistance(-5)).toBeNull();
    });

    it('零距離是合法的（就站在店門口）', () => {
        expect(formatDistance(0)).toBe('0 公尺');
    });
});

describe('formatRating', () => {
    it('沒有人評分就明說，不是印成 0.0 分', () => {
        // 1159 筆餐廳裡只有 1 筆有評分，其餘全部會印「⭐ 0.0 (0)」——
        // 把「還沒有人評分」顯示成「評分 0 分」等於對使用者說謊。
        expect(formatRating(0, 0)).toBe('尚無評分');
    });

    it('有評分才顯示星等與人數', () => {
        expect(formatRating(4.25, 12)).toBe('⭐ 4.3（12）');
        expect(formatRating(5, 1)).toBe('⭐ 5.0（1）');
    });
});
