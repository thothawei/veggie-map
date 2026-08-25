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
