import { describe, expect, it } from 'vitest';
import { formatAddress, formatCuisines, formatDistance, formatOpenStatus } from './format';

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

describe('formatAddress', () => {
    it('把城市、行政區、路名拼成一行', () => {
        expect(formatAddress({
            address: '公益路 100 號',
            city: '台中市',
            district: '西區',
        })).toBe('台中市西區公益路 100 號');
    });

    it('地址裡已經有城市就不再重複', () => {
        expect(formatAddress({
            address: '台中市西區公益路 100 號',
            city: '台中市',
            district: '西區',
        })).toBe('台中市西區公益路 100 號');
    });

    it('完全沒有地址時回 null，讓畫面決定要不要寫「地址未提供」', () => {
        expect(formatAddress({ address: '', city: '', district: '' })).toBeNull();
        expect(formatAddress({ address: '   ' })).toBeNull();
    });
});

describe('formatCuisines', () => {
    it('多個菜系用頓號接起來', () => {
        expect(formatCuisines([
            { code: 'japanese', label: '日式料理' },
            { code: 'thai', label: '泰式料理' },
        ])).toBe('日式料理、泰式料理');
    });

    it('沒有菜系回 null，不要印空白或 undefined', () => {
        expect(formatCuisines([])).toBeNull();
        expect(formatCuisines(undefined)).toBeNull();
    });
});

describe('formatOpenStatus', () => {
    it('營業中會帶上打烊時間', () => {
        expect(formatOpenStatus({ open_status: 'open', closes_at: '21:00' })).toEqual({
            state: 'open',
            text: '營業中・21:00 打烊',
        });
    });

    it('營業中但後端沒給打烊時間就只寫營業中', () => {
        expect(formatOpenStatus({ open_status: 'open' })).toEqual({ state: 'open', text: '營業中' });
    });

    it('休息中會帶上今天稍後的開店時間', () => {
        expect(formatOpenStatus({ open_status: 'closed', next_opens_at: '17:00' })).toEqual({
            state: 'closed',
            text: '休息中・17:00 開始營業',
        });
    });

    /**
     * 這是整個營業時間功能最重要的一條：OSM 多數餐廳沒有 opening_hours，
     * 把「不知道」顯示成「休息中」會讓使用者以為店家關門了。
     */
    it('未知營業時間回 null，畫面不顯示任何狀態', () => {
        expect(formatOpenStatus({ open_status: 'unknown' })).toBeNull();
        expect(formatOpenStatus({})).toBeNull();
    });
});
