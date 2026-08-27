/**
 * 兩點間球面距離（公里），Haversine 公式。抽成獨立函式是為了能寫 Vitest 純邏輯測試——
 * 原本直接寫在 HomeView.vue 裡，元件測試需要整個掛載 Leaflet 地圖，成本不划算。
 */
export function haversineKm(lat1: number, lng1: number, lat2: number, lng2: number): number {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;

    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function formatBbox(bounds: { minLat: number; minLng: number; maxLat: number; maxLng: number }): string {
    return `${bounds.minLat},${bounds.minLng},${bounds.maxLat},${bounds.maxLng}`;
}

/**
 * 這家店在 Google 地圖上的連結。
 *
 * 用**座標**而不是店名當 query：OSM 的店名跟 Google 上的未必一致（分店名、
 * 全形半形、日文店名的漢字寫法都會差），拿名字去搜有機會定位到另一家同名的店。
 * 座標一定指向這家店本人。名字放 label 讓使用者知道自己要開的是誰。
 *
 * 走官方的 Maps URL scheme（api=1），桌機開網頁版、手機會被系統接手開 App。
 */
export function googleMapsUrl(place: { latitude: number; longitude: number }): string {
    return `https://www.google.com/maps/search/?api=1&query=${place.latitude},${place.longitude}`;
}
