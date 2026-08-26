<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import client from '@/api/client';
import RestaurantMap from '@/components/RestaurantMap.vue';
import SearchBox from '@/components/SearchBox.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import CitySwitcher from '@/components/CitySwitcher.vue';
import { rememberCity, useCities } from '@/composables/useCities';
import { apiFilterParams, useFilterQuery } from '@/composables/useFilterQuery';
import { formatAddress, formatConfidence, formatCuisines, formatDistance, formatOpenStatus } from '@/lib/format';
import { formatBbox } from '@/lib/geo';
import type { ApiSuccess, GeocodedPlace, Restaurant, SuggestedRestaurant } from '@/types';

const router = useRouter();
const route = useRoute();

/**
 * 關鍵字跟篩選一樣以網址為真相來源，重新整理與分享連結才留得住
 * （`/?keyword=拉麵`）。後端會比對店名、菜色、料理種類與地區，見
 * `App\Repositories\Search\KeywordSearch`。
 */
const keyword = computed(() => (typeof route.query.keyword === 'string' ? route.query.keyword : ''));

// 地圖一定得看著某個地方，所以退回清單第一個城市，並記住上次選的。
const { cities, loading: citiesLoading, loadFailed: citiesLoadFailed, activeCity, selectCity } = useCities({
    fallback: 'first',
    remember: true,
});

const restaurants = ref<Restaurant[]>([]);
const recommended = ref<Restaurant[]>([]);
const loading = ref(false);
const loadFailed = ref(false);
const hasMore = ref(false);
// 篩選條件跟 city 一樣以網址為真相來源，重新整理與分享連結才留得住。
const filters = useFilterQuery();
const mapRef = ref<InstanceType<typeof RestaurantMap> | null>(null);
const locateError = ref<string | null>(null);

let currentBounds: { minLat: number; minLng: number; maxLat: number; maxLng: number } | null = null;

/**
 * 同時有「地圖移動完」「改篩選」「換城市」三個觸發來源，慢的舊請求可能在新請求之後
 * 才回來，把畫面蓋回舊資料。用序號讓過期的回應直接丟掉。
 */
let requestSeq = 0;

/**
 * 已經為哪個關鍵字調整過視角。用「值變了才收視角」而不是「每次載入都收」：
 * 載入是 moveend 觸發的，每次都 fitBounds 會再觸發一次 moveend，變成無限迴圈。
 * 記成 null 而不是 boolean，是為了讓「分享連結進來時關鍵字已經在網址上」這條
 * 路徑也會收一次視角——那是使用者最需要它的時候。
 */
let lastFittedKeyword: string | null = null;

async function loadByBounds() {
    if (!currentBounds) return;

    const seq = ++requestSeq;
    loading.value = true;
    loadFailed.value = false;

    try {
        const midLat = (currentBounds.minLat + currentBounds.maxLat) / 2;
        const midLng = (currentBounds.minLng + currentBounds.maxLng) / 2;
        // 用地圖目前看到的矩形，不是中心點＋半徑。radius 上限 50km，台中市區半對角線
        // 就超過，一拉遠兩支 API 一起 422，畫面變成「載入失敗」還叫人再拉遠。
        const bbox = formatBbox(currentBounds);
        const filterParams = apiFilterParams(filters.value);

        const [restaurantsResult, recommendedResult] = await Promise.allSettled([
            client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
                params: keyword.value
                    // 打了關鍵字就**不受目前視野限制**，跟列表頁同一個決定：
                    // 搜「Loving Hut」卻只看到畫面裡那幾家，使用者會以為其他地方
                    // 沒有。找到之後 fitToKeywordResults() 會把地圖帶過去。
                    // 也不送座標——帶座標會套上預設 5km 半徑，等於換一種方式把
                    // 搜尋鎖回原地。
                    ? {
                        keyword: keyword.value,
                        sort: 'relevance',
                        per_page: 100,
                        ...filterParams,
                    }
                    : {
                        bbox,
                        latitude: midLat,
                        longitude: midLng,
                        sort: 'distance',
                        per_page: 100,
                        ...filterParams,
                    },
            }),
            // 後端 RuleBasedRecommendationService 依 distance/rating/vegetarian_confidence/
            // feature_match/popularity/freshness 加權排序（見總體規劃第三十節），不是單純
            // 依評分排序，所以是獨立一支 API，不是從上面那批結果在前端隨便切幾筆。
            client.get<ApiSuccess<Restaurant[]>>('/restaurants/recommended', {
                params: {
                    bbox,
                    latitude: midLat,
                    longitude: midLng,
                    limit: 6,
                    ...filterParams,
                },
            }),
        ]);

        if (seq !== requestSeq) return;

        if (restaurantsResult.status === 'fulfilled') {
            restaurants.value = restaurantsResult.value.data.data;
            hasMore.value = Boolean(restaurantsResult.value.data.meta?.next_cursor);
            loadFailed.value = false;
            fitToKeywordResults();
        } else {
            loadFailed.value = true;
            restaurants.value = [];
            hasMore.value = false;
        }

        recommended.value =
            recommendedResult.status === 'fulfilled' ? recommendedResult.value.data.data : [];
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

/**
 * 命中的店可能不在目前視野內。有結果就把地圖收到它們身上；沒有結果就不動視角，
 * 讓空狀態說明「這個範圍沒有符合的店」，而不是把使用者丟到不知道哪裡。
 */
function fitToKeywordResults() {
    if (!keyword.value || keyword.value === lastFittedKeyword) {
        return;
    }

    if (restaurants.value.length === 0) {
        return;
    }

    lastFittedKeyword = keyword.value;
    mapRef.value?.fitToRestaurants(
        restaurants.value.map((restaurant): [number, number] => [restaurant.latitude, restaurant.longitude]),
    );
}

function handleBoundsChanged(bounds: { minLat: number; minLng: number; maxLat: number; maxLng: number }) {
    currentBounds = bounds;
    loadByBounds();
}

function handlePlaceSelected(place: GeocodedPlace) {
    mapRef.value?.flyTo(place.latitude, place.longitude);
}

/**
 * 「拉麵」這種詞 geocode 查不到地點，但後端搜尋得到。寫進網址後由 watch 觸發重查，
 * 再把地圖視角收到命中的餐廳上——否則使用者搜完還要自己把地圖拖到對的地方。
 */
async function handleKeywordSearch(value: string) {
    await router.push({ query: { ...route.query, keyword: value || undefined } });
}

function clearKeyword() {
    const query = { ...route.query };
    delete query.keyword;
    router.push({ query });
}

function handleLocate() {
    locateError.value = null;
    mapRef.value?.locateUser();
}

function handleLocateFailed() {
    locateError.value = '無法取得目前位置，請檢查定位權限後再試。';
}

function goToDetail(restaurant: Restaurant | SuggestedRestaurant) {
    // slug 優先：網址看得懂是規劃第二十六節的目的。沒有 slug 就退回 id，
    // 後端兩種都收。
    router.push({
        name: 'restaurant-detail',
        params: { id: restaurant.slug ?? restaurant.id },
    });
}

watch(filters, loadByBounds, { deep: true });

watch(keyword, loadByBounds);

watch(activeCity, (city, previous) => {
    if (!city) return;

    rememberCity(city.slug);

    // 第一次是地圖自己用 :center 開好的，不用再飛一次（會多打一輪 API）。
    if (!previous) return;
    if (city.slug === previous.slug) return;

    mapRef.value?.jumpTo(city.center[0], city.center[1], city.zoom);
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
                <SearchBox
                    @place-selected="handlePlaceSelected"
                    @keyword-search="handleKeywordSearch"
                    @restaurant-selected="goToDetail"
                />
                <button type="button" class="locate-button" @click="handleLocate">📍 使用目前位置</button>
            </div>
            <p v-if="locateError" class="locate-error" role="alert">{{ locateError }}</p>
            <p v-if="keyword" class="keyword-badge" role="status">
                只顯示符合「{{ keyword }}」的餐廳
                <button type="button" @click="clearKeyword">清除</button>
            </p>
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
                @locate-failed="handleLocateFailed"
            />
            <div v-else class="map-placeholder">
                <span v-if="citiesLoading">地圖準備中…</span>
                <span v-else-if="citiesLoadFailed">城市清單載入失敗，請重新整理。</span>
                <span v-else>目前沒有可顯示的城市。</span>
            </div>

            <p v-if="loading" class="map-badge" role="status">載入中…</p>
            <p v-else-if="loadFailed" class="map-badge error" role="alert">
                載入失敗，移動地圖可重新嘗試。
            </p>
            <p v-else-if="hasResults" class="map-badge" role="status">
                <template v-if="keyword">
                    符合「{{ keyword }}」的有 {{ restaurants.length }}{{ hasMore ? '+' : '' }} 家（不限目前範圍）
                </template>
                <template v-else>
                    這個範圍有 {{ restaurants.length }}{{ hasMore ? '+' : '' }} 家
                </template>
            </p>
        </section>

        <section v-if="showEmptyState" class="empty-state">
            <p class="empty-title">
                <template v-if="keyword">找不到符合「{{ keyword }}」的餐廳</template>
                <template v-else>這個範圍還沒有素食餐廳</template>
            </p>
            <p class="empty-hint">
                <template v-if="keyword">
                    這個關鍵字在所有城市都沒有結果——換個說法，或清掉關鍵字回到地圖瀏覽。
                </template>
                <template v-else>試著把地圖拉遠一點，或切換到其他城市看看。</template>
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
                    <span
                        v-if="restaurant.venue_badge"
                        class="venue-badge"
                        :data-kind="restaurant.venue_kind ?? undefined"
                    >{{ restaurant.venue_badge }}</span>
                    <span v-if="formatCuisines(restaurant.cuisines)" class="cuisines">{{ formatCuisines(restaurant.cuisines) }}</span>
                    <span v-if="restaurant.venue_summary" class="venue-summary">{{ restaurant.venue_summary }}</span>
                    <span class="meta">
                        <span v-if="formatDistance(restaurant.distance_meters)" class="distance">
                            {{ formatDistance(restaurant.distance_meters) }}
                        </span>
                        <span
                            v-if="formatConfidence(restaurant.confidence_score)"
                            class="confidence"
                        >{{ formatConfidence(restaurant.confidence_score) }}</span>
                        <span
                            v-if="formatOpenStatus(restaurant)"
                            class="open-status"
                            :data-state="formatOpenStatus(restaurant)?.state"
                        >{{ formatOpenStatus(restaurant)?.text }}</span>
                    </span>
                    <span class="address">{{ formatAddress(restaurant) ?? '地址未提供' }}</span>
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

.locate-error {
    margin: 0.5rem 0 0;
    color: #c53030;
    font-size: 0.9rem;
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

.card .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.card .distance {
    color: #2f855a;
    font-weight: 600;
}

.card .address {
    color: #2d3748;
    font-size: 0.9rem;
}

.cuisines {
    color: #2f855a;
    font-size: 0.85rem;
}

.venue-badge {
    align-self: flex-start;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    background: #f0fff4;
    color: #276749;
    font-size: 0.75rem;
}

.venue-badge[data-kind='friendly'] {
    background: #ebf8ff;
    color: #2b6cb0;
}

.venue-summary {
    color: #4a5568;
    font-size: 0.8rem;
}

@media (max-width: 640px) {
    .map-section {
        height: 55vh;
    }
}

.open-status[data-state='open'] {
    color: #2f855a;
    font-weight: 600;
}

.open-status[data-state='closed'] {
    color: #718096;
}

.keyword-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0.5rem auto 0;
    padding: 0.3rem 0.75rem;
    border-radius: 999px;
    background: #f0fff4;
    color: #22543d;
    font-size: 0.85rem;
}

.keyword-badge button {
    border: none;
    background: none;
    color: #2f855a;
    cursor: pointer;
    text-decoration: underline;
    font-size: 0.85rem;
}

.confidence {
    color: #2c5282;
    font-size: 0.85rem;
}
</style>
