<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet.markercluster';
import { formatAddress, formatCuisines, formatDistance, formatOpenStatus } from '@/lib/format';
import { googleMapsUrl, haversineKm } from '@/lib/geo';
import { escapeHtml } from '@/lib/html';
import type { Restaurant } from '@/types';

const props = defineProps<{
    restaurants: Restaurant[];
    center: [number, number];
    zoom: number;
}>();

const emit = defineEmits<{
    (e: 'bounds-changed', bounds: { minLat: number; minLng: number; maxLat: number; maxLng: number; center: [number, number] }): void;
    (e: 'select', restaurant: Restaurant): void;
    (e: 'locate', coords: [number, number]): void;
    (e: 'locate-failed'): void;
}>();

let map: L.Map | null = null;
let clusterGroup: L.MarkerClusterGroup | null = null;
const mapEl = ref<HTMLDivElement | null>(null);

let resizeObserver: ResizeObserver | null = null;

function emitBounds() {
    if (!map) return;
    const b = map.getBounds();
    const c = map.getCenter();

    // 掛載當下容器可能還沒有寬高（實測首次渲染時 east === west），算出來的 bbox
    // 是一條線。送出去後端會回 422，畫面閃一下「載入失敗」再自己修正——看起來
    // 像壞掉，實際上只是還沒量到尺寸。退化的 bbox 直接不送，等 moveend 或
    // invalidateSize 之後那次。
    if (b.getEast() === b.getWest() || b.getNorth() === b.getSouth()) {
        return;
    }

    emit('bounds-changed', {
        minLat: b.getSouth(),
        minLng: b.getWest(),
        maxLat: b.getNorth(),
        maxLng: b.getEast(),
        center: [c.lat, c.lng],
    });
}

/**
 * marker 依「純素食店 / 素食友善」上色。
 *
 * 這是這張地圖上最重要的一個區別：純素食店整間都能吃，素食友善店是葷素都有、
 * 菜單有無肉選項。全部長一樣的話，使用者得逐個點開才知道——而那正是他要用地圖
 * 的原因。
 *
 * 用 divIcon（CSS 畫的圓點）而不是兩張 png：不必新增圖檔、換配色只改 CSS，
 * 而且 retina 螢幕不會糊。
 *
 * `venue_kind` 只有在後端 eager load 過 dietTypes 時才有；沒有就退回中性樣式，
 * 不猜成其中一種。
 */
function markerIcon(restaurant: Restaurant): L.DivIcon {
    const kind = restaurant.venue_kind ?? 'unknown';

    return L.divIcon({
        className: '',
        html: `<span class="veggie-marker" data-kind="${kind}"></span>`,
        iconSize: [18, 18],
        iconAnchor: [9, 9],
        popupAnchor: [0, -9],
    });
}

function renderMarkers() {
    if (!map || !clusterGroup) return;
    clusterGroup.clearLayers();

    for (const restaurant of props.restaurants) {
        const marker = L.marker([restaurant.latitude, restaurant.longitude], {
            icon: markerIcon(restaurant),
            // 圖示帶顏色但顏色本身不是資訊——螢幕閱讀器與色盲使用者靠這個。
            alt: `${restaurant.name}（${restaurant.venue_badge ?? '素食餐廳'}）`,
        });
        const address = formatAddress(restaurant) ?? '地址未提供';
        const cuisines = formatCuisines(restaurant.cuisines);
        const distance = formatDistance(restaurant.distance_meters);
        const openStatus = formatOpenStatus(restaurant);
        marker.bindPopup(
            `<strong>${escapeHtml(restaurant.name)}</strong>` +
                (restaurant.venue_badge
                    ? `<br><span class="venue-badge">${escapeHtml(restaurant.venue_badge)}</span>`
                    : '') +
                (cuisines ? `<br>${escapeHtml(cuisines)}` : '') +
                (restaurant.venue_summary ? `<br>${escapeHtml(restaurant.venue_summary)}` : '') +
                `<br>${escapeHtml(address)}` +
                (distance ? `<br>${escapeHtml(distance)}` : '') +
                (openStatus
                    ? `<br><span class="open-status" data-state="${openStatus.state}">${escapeHtml(openStatus.text)}</span>`
                    : '') +
                /*
                 * 兩個出口。在這之前 marker 的 click 直接導航到詳情頁，popup 綁了
                 * 卻永遠沒機會顯示——連同它的測試在維護一個沒人看得到的 UI
                 * （2026-08-27 實測）。
                 *
                 * 「看詳情」不能寫成 <a href>：那會整頁重載，把 SPA 的路由與地圖狀態
                 * 全部丟掉。用 data 屬性標記，popupopen 時再接上 Vue 的導航。
                 */
                '<span class="popup-actions">' +
                `<button type="button" data-detail="${restaurant.id}">看詳情</button>` +
                `<a href="${escapeHtml(googleMapsUrl(restaurant))}" target="_blank" rel="noopener noreferrer">在 Google 地圖開啟</a>` +
                '</span>',
        );

        /*
         * 點 marker = 開 popup（Leaflet 的預設行為），不再直接導航。
         * 導航改由 popup 裡的「看詳情」觸發。
         */
        marker.on('popupopen', (event: L.PopupEvent) => {
            const button = event.popup.getElement()?.querySelector<HTMLButtonElement>('[data-detail]');

            if (button) {
                /*
                 * 用 onclick 賦值而不是 addEventListener：Leaflet 關閉再開啟同一個
                 * marker 的 popup 時**重用同一份 DOM**（2026-08-27 在瀏覽器實測，
                 * 關掉再開的 popup element 與 button element 都是同一個物件）。
                 * addEventListener 會一次疊一個，開關三次就發三次 select。
                 * 賦值是覆寫，開幾次都只有一個 handler。
                 */
                button.onclick = () => emit('select', restaurant);
            }
        });

        clusterGroup.addLayer(marker);
    }
}

/**
 * 超過這個距離就不要做飛行動畫。Leaflet 的 flyTo 跨長距離（實測台北→台南約 200km）
 * 會把 tile layer 的 transform 留在壞掉的狀態：磁磚被排到容器外好幾千 px，畫面一片空白，
 * 而 marker 走另一條 pane 路徑所以位置照樣正確——看起來像「地圖破圖但標記還在」。
 * 改用 setView 直接跳就沒事，而且跨城市本來就不該花好幾秒飛過去。
 */
const MAX_ANIMATED_FLIGHT_KM = 50;

defineExpose({
    flyTo(lat: number, lng: number, zoom = 15) {
        if (!map) return;

        const from = map.getCenter();

        if (haversineKm(from.lat, from.lng, lat, lng) > MAX_ANIMATED_FLIGHT_KM) {
            map.setView([lat, lng], zoom);

            return;
        }

        map.flyTo([lat, lng], zoom);
    },
    /**
     * 關鍵字搜尋用：把視角收到這批餐廳的範圍。只有一筆時 fitBounds 會縮到最大倍率，
     * 所以單點改用 flyTo 的行為（zoom 16）——不然畫面會變成一片建築物看不出在哪。
     */
    fitToRestaurants(points: Array<[number, number]>) {
        if (!map || points.length === 0) return;

        if (points.length === 1) {
            map.setView(points[0], 16);

            return;
        }

        map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 16 });
    },
    /** 城市切換用：一律直接跳，不做動畫。 */
    jumpTo(lat: number, lng: number, zoom: number) {
        map?.setView([lat, lng], zoom);
    },
    locateUser() {
        if (!navigator.geolocation) {
            emit('locate-failed');

            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const coords: [number, number] = [position.coords.latitude, position.coords.longitude];
                map?.flyTo(coords, 15);
                emit('locate', coords);
            },
            () => {
                emit('locate-failed');
            },
            { timeout: 8000 },
        );
    },
});

onMounted(() => {
    map = L.map(mapEl.value as HTMLDivElement).setView(props.center, props.zoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    clusterGroup = L.markerClusterGroup();
    map.addLayer(clusterGroup);

    map.on('moveend', emitBounds);
    renderMarkers();
    emitBounds();

    // 容器在掛載當下可能還沒有寬高（見 emitBounds 的退化 bbox 判斷）。只是「不送」
    // 還不夠——`moveend` 不會因為容器變大而觸發，那次載入就永遠不會補回來，畫面
    // 停在空地圖（2026-08-26 實測，這正是加上退化判斷之後引進的回歸）。
    // 尺寸一確定就叫 Leaflet 重新量測，並補送一次 bbox。
    resizeObserver = new ResizeObserver(() => {
        if (!map) return;

        map.invalidateSize();
        emitBounds();
    });
    resizeObserver.observe(mapEl.value as HTMLDivElement);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
    map?.remove();
    map = null;
});

watch(() => props.restaurants, renderMarkers, { deep: false });
</script>

<template>
    <div ref="mapEl" class="restaurant-map"></div>
</template>

<style scoped>
.restaurant-map {
    width: 100%;
    height: 100%;
    min-height: 400px;
}
</style>
