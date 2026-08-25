<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import client from '@/api/client';
import RestaurantMap from '@/components/RestaurantMap.vue';
import SearchBox from '@/components/SearchBox.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import CitySwitcher from '@/components/CitySwitcher.vue';
import { haversineKm } from '@/lib/geo';
import type { ApiSuccess, City, GeocodedPlace, Restaurant, RestaurantSearchParams } from '@/types';

const router = useRouter();
const route = useRoute();

const LAST_CITY_KEY = 'veggiemap:last-city';

const cities = ref<City[]>([]);
const citiesLoading = ref(true);
const restaurants = ref<Restaurant[]>([]);
const recommended = ref<Restaurant[]>([]);
const loading = ref(false);
const loadFailed = ref(false);
const hasMore = ref(false);
const filters = ref<Partial<RestaurantSearchParams>>({});
const mapRef = ref<InstanceType<typeof RestaurantMap> | null>(null);

let currentBounds: { minLat: number; minLng: number; maxLat: number; maxLng: number } | null = null;

/**
 * 網址是「目前在哪個城市」的單一真相來源：切換器只負責改網址，實際飛過去由下面的
 * watch 處理。這樣上一頁／下一頁、重新整理、把連結貼給別人三件事自動都對，不用另外
 * 維護一份會跟網址對不起來的內部狀態。
 */
const activeCity = computed<City | null>(() => {
    if (!cities.value.length) return null;

    const slug = route.query.city;

    return cities.value.find((city) => city.slug === slug) ?? cities.value[0];
});

/**
 * 同時有「地圖移動完」「改篩選」「換城市」三個觸發來源，慢的舊請求可能在新請求之後
 * 才回來，把畫面蓋回舊資料。用序號讓過期的回應直接丟掉。
 */
let requestSeq = 0;

async function loadByBounds() {
    if (!currentBounds) return;

    const seq = ++requestSeq;
    loading.value = true;
    loadFailed.value = false;

    try {
        const midLat = (currentBounds.minLat + currentBounds.maxLat) / 2;
        const midLng = (currentBounds.minLng + currentBounds.maxLng) / 2;
        // 地圖首頁依 bounds 撈餐廳，不是撈全部——用 bounds 中心點 + 對角線距離當半徑，
        // 交給後端既有的半徑搜尋（見 docs/database.md 的兩段式查詢），不用另開一支 API。
        const radiusKm = haversineKm(currentBounds.minLat, currentBounds.minLng, currentBounds.maxLat, currentBounds.maxLng) / 2;
        const radius = Math.max(radiusKm, 0.5);

        const [restaurantsRes, recommendedRes] = await Promise.all([
            client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
                params: {
                    latitude: midLat,
                    longitude: midLng,
                    radius,
                    sort: 'distance',
                    per_page: 100,
                    ...filters.value,
                },
            }),
            // 後端 RuleBasedRecommendationService 依 distance/rating/vegetarian_confidence/
            // feature_match/popularity/freshness 加權排序（見總體規劃第三十節），不是單純
            // 依評分排序，所以是獨立一支 API，不是從上面那批結果在前端隨便切幾筆。
            client.get<ApiSuccess<Restaurant[]>>('/restaurants/recommended', {
                params: { latitude: midLat, longitude: midLng, radius, limit: 6 },
            }),
        ]);

        if (seq !== requestSeq) return;

        restaurants.value = restaurantsRes.data.data;
        recommended.value = recommendedRes.data.data;
        // 後端是 cursor 分頁、不回總數，per_page=100 是上限。next_cursor 還在就代表這個
        // 範圍不只 100 家——要顯示成「100+」，不能把被截斷的數字當成總數講。
        hasMore.value = Boolean(restaurantsRes.data.meta?.next_cursor);
    } catch {
        if (seq !== requestSeq) return;

        loadFailed.value = true;
        restaurants.value = [];
        recommended.value = [];
        hasMore.value = false;
    } finally {
        if (seq === requestSeq) {
            loading.value = false;
        }
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

function selectCity(slug: string) {
    router.push({ query: { ...route.query, city: slug } });
}

function goToDetail(restaurant: Restaurant) {
    router.push({ name: 'restaurant-detail', params: { id: restaurant.id } });
}

watch(filters, loadByBounds, { deep: true });

watch(activeCity, (city, previous) => {
    if (!city) return;

    localStorage.setItem(LAST_CITY_KEY, city.slug);

    // 第一次是地圖自己用 :center 開好的，不用再飛一次（會多打一輪 API）。
    if (!previous) return;
    if (city.slug === previous.slug) return;

    mapRef.value?.jumpTo(city.center[0], city.center[1], city.zoom);
});

onMounted(async () => {
    try {
        const { data } = await client.get<ApiSuccess<City[]>>('/cities');
        cities.value = data.data;
    } finally {
        citiesLoading.value = false;
    }

    if (!cities.value.length) return;

    // 網址沒指定就沿用上次看的城市，兩者都沒有才用清單第一個。用 replace 而不是 push，
    // 免得使用者一進站就在歷史紀錄裡多一筆、按上一頁還回不去。
    if (!route.query.city) {
        const remembered = localStorage.getItem(LAST_CITY_KEY);
        const fallback = cities.value.find((city) => city.slug === remembered) ?? cities.value[0];

        router.replace({ query: { ...route.query, city: fallback.slug } });
    }
});

const hasResults = computed(() => restaurants.value.length > 0);
// 篩選被切掉後鍵可能還在（值是 undefined），直接數 Object.keys 會謊報「還有篩選條件」。
const hasActiveFilters = computed(
    () => Object.values(filters.value).some((value) => value !== undefined && value !== null),
);
const showEmptyState = computed(() => !loading.value && !loadFailed.value && !hasResults.value && currentBounds !== null);
</script>

<template>
    <div class="home">
        <section class="hero">
            <h1>VeggieMap</h1>
            <p class="tagline">找到適合你的素食餐廳</p>

            <CitySwitcher
                v-if="cities.length"
                :cities="cities"
                :model-value="activeCity?.slug ?? null"
                @update:model-value="selectCity"
            />

            <div class="hero-controls">
                <SearchBox @place-selected="handlePlaceSelected" />
                <button type="button" class="locate-button" @click="handleLocate">📍 使用目前位置</button>
            </div>
            <FilterDrawer v-model:filters="filters" />
        </section>

        <section class="map-section">
            <RestaurantMap
                v-if="activeCity"
                ref="mapRef"
                :restaurants="restaurants"
                :center="activeCity.center"
                :zoom="activeCity.zoom"
                @bounds-changed="handleBoundsChanged"
                @select="goToDetail"
            />
            <div v-else class="map-placeholder">
                <span v-if="citiesLoading">地圖準備中…</span>
                <span v-else>目前沒有可顯示的城市。</span>
            </div>

            <p v-if="loading" class="map-badge" role="status">載入中…</p>
            <p v-else-if="loadFailed" class="map-badge error" role="alert">
                載入失敗，移動地圖可重新嘗試。
            </p>
            <p v-else-if="hasResults" class="map-badge" role="status">
                這個範圍有 {{ restaurants.length }}{{ hasMore ? '+' : '' }} 家
            </p>
        </section>

        <section v-if="showEmptyState" class="empty-state">
            <p class="empty-title">這個範圍還沒有素食餐廳</p>
            <p class="empty-hint">
                試著把地圖拉遠一點，或切換到其他城市看看。
                <template v-if="hasActiveFilters"> 也可以先清掉篩選條件。</template>
            </p>
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

.tagline {
    margin: 0.25rem 0 1rem;
    color: #4a5568;
}

.hero-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    max-width: 640px;
    margin: 0 auto;
    align-items: flex-start;
}

.hero-controls .search-box {
    flex: 1;
    min-width: 200px;
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

.map-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    min-height: 400px;
    background: #f7fafc;
    color: #718096;
}

.map-badge {
    position: absolute;
    top: 0.5rem;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    background: #fff;
    padding: 0.3rem 0.85rem;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgb(0 0 0 / 12%);
    z-index: 1000;
    font-size: 0.85rem;
    white-space: nowrap;
}

.map-badge.error {
    border-color: #fc8181;
    color: #c53030;
}

.empty-state {
    padding: 1.5rem;
    text-align: center;
}

.empty-title {
    margin: 0 0 0.35rem;
    font-weight: 600;
}

.empty-hint {
    margin: 0;
    color: #718096;
    font-size: 0.9rem;
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

.card:hover {
    border-color: #2f855a;
}

.card .address {
    color: #718096;
    font-size: 0.85rem;
}

@media (max-width: 640px) {
    .map-section {
        height: 55vh;
    }
}
</style>
