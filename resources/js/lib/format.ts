/**
 * 顯示用的格式化。抽成純函式是為了能寫 Vitest 測試，也讓地圖 popup（字串拼接）
 * 與卡片（template）用同一份規則，不會兩邊各寫一套。
 */

import type { Cuisine, MatchedReason } from '@/types';

/**
 * 後端 `distance_meters` 只在帶座標查詢時才有（見 RestaurantResource），
 * 所以呼叫端可能拿到 undefined——回 null 讓 template 用 v-if 決定要不要顯示。
 *
 * 一公里以內用公尺並取整到十位：距離本身有 GPS 誤差，寫「389.4 公尺」是假精確。
 */
export function formatDistance(meters: number | null | undefined): string | null {
    if (typeof meters !== 'number' || !Number.isFinite(meters) || meters < 0) {
        return null;
    }

    if (meters < 1000) {
        return `${Math.round(meters / 10) * 10} 公尺`;
    }

    return `${(meters / 1000).toFixed(1)} 公里`;
}

/**
 * city／district／address 拼成一行。OSM 常把城市跟路名拆在不同欄位，只印
 * `address` 會變成「信義路 7」這種看不出在哪的半截。已經含在地址裡的部分不加第二次。
 */
export function formatAddress(restaurant: {
    address?: string | null;
    city?: string | null;
    district?: string | null;
}): string | null {
    const address = restaurant.address?.trim() ?? '';
    const city = restaurant.city?.trim() ?? '';
    const district = restaurant.district?.trim() ?? '';
    const parts: string[] = [];

    if (city && !address.includes(city)) {
        parts.push(city);
    }

    if (district && !address.includes(district) && !city.includes(district)) {
        parts.push(district);
    }

    if (address) {
        parts.push(address);
    }

    const result = parts.join('');

    return result === '' ? null : result;
}

/** 地圖 popup 用的一行菜系；沒有就不顯示，不要印「undefined」。 */
export function formatCuisines(cuisines?: Cuisine[] | null): string | null {
    if (!cuisines?.length) {
        return null;
    }

    return cuisines.map((item) => item.label).join('、');
}

/**
 * 營業狀態的畫面文字。
 *
 * 回傳 null 代表「這家店的營業時間我們沒有資料」——刻意不顯示任何東西，而不是
 * 顯示「已打烊」。OSM 多數餐廳沒有 opening_hours 標籤，把未知說成打烊會誤導。
 */
export function formatOpenStatus(restaurant: {
    open_status?: 'open' | 'closed' | 'unknown';
    closes_at?: string;
    next_opens_at?: string;
}): { text: string; state: 'open' | 'closed' } | null {
    if (restaurant.open_status === 'open') {
        return {
            state: 'open',
            text: restaurant.closes_at ? `營業中・${restaurant.closes_at} 打烊` : '營業中',
        };
    }

    if (restaurant.open_status === 'closed') {
        return {
            state: 'closed',
            text: restaurant.next_opens_at ? `休息中・${restaurant.next_opens_at} 開始營業` : '休息中',
        };
    }

    return null;
}

/**
 * 命中原因的畫面文字：「料理種類：日式拉麵・命中菜色：味噌拉麵」。
 *
 * `name` 刻意不顯示：店名就印在同一張卡片的第一行，再說一次「店名：拉麵屋」
 * 只是雜訊（這是 matched_menu_items 時代就有的判斷，沿用）。所以全部命中原因
 * 都是 name 時回 null，卡片上不會多一行。
 *
 * 同型別的多筆會合併成一行（「命中菜色：味噌拉麵、醬油拉麵」），不是各印一行。
 */
export function formatMatchReasons(reasons?: MatchedReason[] | null): string | null {
    const LABELS: Record<MatchedReason['type'], string | null> = {
        name: null,
        menu_item: '命中菜色',
        cuisine: '料理種類',
        locality: '地區',
        description: '描述',
        diet: '飲食類型',
    };

    const grouped = new Map<string, string[]>();

    for (const reason of reasons ?? []) {
        const label = LABELS[reason.type];

        if (label === null || label === undefined) {
            continue;
        }

        const values = grouped.get(label) ?? [];

        // 同一個值出現兩次（例如兩道菜同名）只說一次。
        if (!values.includes(reason.value)) {
            values.push(reason.value);
        }

        grouped.set(label, values);
    }

    if (grouped.size === 0) {
        return null;
    }

    return [...grouped].map(([label, values]) => `${label}：${values.join('、')}`).join('・');
}

/**
 * 素食可信度的畫面文字。
 *
 * **裸分數只留給沒有 `confidence_level` 的舊使用端**（詳情頁的分數明細仍然要
 * 印數字，那裡有 breakdown 說明每一分怎麼來的，不會被誤讀成評分）。列表卡片
 * 請用後端給的 `confidence_level.label`，見 config/vegetarian.php 的說明。
 *
 * 回傳 null 代表沒有分數——不要印「0 分」：0 跟「還沒有人查證過」在使用者眼裡
 * 是兩件事，前者看起來像這家店被判定不可信。
 */
export function formatConfidence(score: number | null | undefined): string | null {
    if (typeof score !== 'number' || !Number.isFinite(score) || score <= 0) {
        return null;
    }

    return `素食可信度 ${Math.round(score)}`;
}
