<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet.markercluster';
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
        marker.bindPopup(
            `<strong>${restaurant.name}</strong><br>${restaurant.address}<br>⭐ ${restaurant.rating.toFixed(1)} (${restaurant.rating_count})`,
        );
        marker.on('click', () => emit('select', restaurant));
        clusterGroup.addLayer(marker);
    }
}

defineExpose({
    flyTo(lat: number, lng: number, zoom = 15) {
        map?.flyTo([lat, lng], zoom);
    },
    locateUser() {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const coords: [number, number] = [position.coords.latitude, position.coords.longitude];
                map?.flyTo(coords, 15);
                emit('locate', coords);
            },
            () => {
                // 使用者拒絕定位權限或裝置沒有 GPS，靜默失敗，保留地圖原本位置。
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
