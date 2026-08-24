<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import client from '@/api/client';
import RestaurantMap from '@/components/RestaurantMap.vue';
import SearchBox from '@/components/SearchBox.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import { haversineKm } from '@/lib/geo';
import type { ApiSuccess, GeocodedPlace, Restaurant, RestaurantSearchParams } from '@/types';

const router = useRouter();

const TAICHUNG: [number, number] = [24.1477, 120.6736];

const restaurants = ref<Restaurant[]>([]);
const loading = ref(false);
const filters = ref<Partial<RestaurantSearchParams>>({});
const mapRef = ref<InstanceType<typeof RestaurantMap> | null>(null);
const center = ref<[number, number]>(TAICHUNG);

let currentBounds: { minLat: number; minLng: number; maxLat: number; maxLng: number } | null = null;

async function loadByBounds() {
    if (!currentBounds) return;

    loading.value = true;
    try {
        const midLat = (currentBounds.minLat + currentBounds.maxLat) / 2;
        const midLng = (currentBounds.minLng + currentBounds.maxLng) / 2;
        // 地圖首頁依 bounds 撈餐廳，不是撈全部——用 bounds 中心點 + 對角線距離當半徑，
        // 交給後端既有的半徑搜尋（見 docs/database.md 的兩段式查詢），不用另開一支 API。
        const radiusKm = haversineKm(currentBounds.minLat, currentBounds.minLng, currentBounds.maxLat, currentBounds.maxLng) / 2;

        const response = await client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
            params: {
                latitude: midLat,
                longitude: midLng,
                radius: Math.max(radiusKm, 0.5),
                sort: 'distance',
                per_page: 100,
                ...filters.value,
            },
        });
        restaurants.value = response.data.data;
    } finally {
        loading.value = false;
    }
}

function handleBoundsChanged(bounds: { minLat: number; minLng: number; maxLat: number; maxLng: number }) {
    currentBounds = bounds;
    loadByBounds();
}

function handlePlaceSelected(place: GeocodedPlace) {
    mapRef.value?.flyTo(place.latitude, place.longitude);
}

function handleLocate() {
    mapRef.value?.locateUser();
}

function goToDetail(restaurant: Restaurant) {
    router.push({ name: 'restaurant-detail', params: { id: restaurant.id } });
}

const recommended = computed(() =>
    [...restaurants.value].sort((a, b) => b.rating - a.rating).slice(0, 6),
);

watch(filters, loadByBounds, { deep: true });
</script>

<template>
    <div class="home">
        <section class="hero">
            <h1>VeggieMap</h1>
            <p>找到適合你的素食餐廳</p>
            <div class="hero-controls">
                <SearchBox @place-selected="handlePlaceSelected" />
                <button type="button" class="locate-button" @click="handleLocate">📍 使用目前位置</button>
            </div>
            <FilterDrawer v-model:filters="filters" />
        </section>

        <section class="map-section">
            <RestaurantMap
                ref="mapRef"
                :restaurants="restaurants"
                :center="center"
                :zoom="13"
                @bounds-changed="handleBoundsChanged"
                @select="goToDetail"
            />
            <p v-if="loading" class="map-loading">載入中…</p>
        </section>

        <section class="recommended" v-if="recommended.length">
            <h2>推薦餐廳</h2>
            <div class="cards">
                <button
                    v-for="restaurant in recommended"
                    :key="restaurant.id"
                    type="button"
                    class="card"
                    @click="goToDetail(restaurant)"
                >
                    <strong>{{ restaurant.name }}</strong>
                    <span>⭐ {{ restaurant.rating.toFixed(1) }} ({{ restaurant.rating_count }})</span>
                    <span class="address">{{ restaurant.address }}</span>
                </button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.hero {
    padding: 1.5rem 1rem;
    text-align: center;
    background: #f0fff4;
}

.hero h1 {
    margin: 0;
    color: #2f855a;
}

.hero-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    max-width: 640px;
    margin: 1rem auto 0;
    align-items: flex-start;
}

.hero-controls .search-box {
    flex: 1;
}

.locate-button {
    padding: 0.5rem 0.75rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    white-space: nowrap;
}

.map-section {
    position: relative;
    height: 60vh;
}

.map-loading {
    position: absolute;
    top: 0.5rem;
    left: 50%;
    transform: translateX(-50%);
    background: #fff;
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    z-index: 1000;
    font-size: 0.85rem;
}

.recommended {
    padding: 1.5rem;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
}

.card {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    text-align: left;
}

.card .address {
    color: #718096;
    font-size: 0.85rem;
}
</style>
