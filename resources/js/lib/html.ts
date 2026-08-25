/**
 * Leaflet popup 吃 HTML。OSM 店名偶爾帶 `<`／`&`，不跳脫會變成 markup，
 * 最糟是可執行的內容。
 */
export function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
