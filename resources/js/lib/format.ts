/**
 * 顯示用的格式化。抽成純函式是為了能寫 Vitest 測試，也讓地圖 popup（字串拼接）
 * 與卡片（template）用同一份規則，不會兩邊各寫一套。
 */

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
 * 目前 1159 筆餐廳裡只有 1 筆有評分（OSM 匯入的一筆都沒有），其餘全部會印成
 * 「⭐ 0.0 (0)」——把「還沒有人評分」顯示成「評分 0 分」，等於對使用者說謊，
 * 而且每張卡都印一次反而蓋掉真正有用的資訊。沒有評分就明說沒有。
 */
export function formatRating(rating: number, ratingCount: number): string {
    if (!ratingCount) {
        return '尚無評分';
    }

    return `⭐ ${rating.toFixed(1)}（${ratingCount}）`;
}
