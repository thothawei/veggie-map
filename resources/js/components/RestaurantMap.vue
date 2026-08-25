<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet.markercluster';
import { formatAddress, formatCuisines, formatDistance, formatOpenStatus } from '@/lib/format';
import { haversineKm } from '@/lib/geo';
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

function emitBounds() {
    if (!map) return;
    const b = map.getBounds();
    const c = map.getCenter();
    emit('bounds-changed', {
        minLat: b.getSouth(),
        minLng: b.getWest(),
        maxLat: b.getNorth(),
        maxLng: b.getEast(),
        center: [c.lat, c.lng],
    });
}

function renderMarkers() {
    if (!map || !clusterGroup) return;
    clusterGroup.clearLayers();

    for (const restaurant of props.restaurants) {
        const marker = L.marker([restaurant.latitude, restaurant.longitude]);
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
                    : ''),
        );
        marker.on('click', () => emit('select', restaurant));
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
});

onBeforeUnmount(() => {
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
