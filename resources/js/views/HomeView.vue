<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import client from '@/api/client';
import RestaurantMap from '@/components/RestaurantMap.vue';
import SearchBox from '@/components/SearchBox.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import ScopeSelect from '@/components/ScopeSelect.vue';
import CitySwitcher from '@/components/CitySwitcher.vue';
import { rememberCity, useCities } from '@/composables/useCities';
import { apiFilterParams, useFilterQuery } from '@/composables/useFilterQuery';
import { SEARCH_SCOPES, useSearchScope, type SearchScope } from '@/composables/useSearchScope';
import { formatAddress, formatCuisines, formatDistance, formatOpenStatus } from '@/lib/format';
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

/** 條件本身不合法（422），跟連線失敗分開講——後者才值得重試。 */
const invalidFilters = ref(false);
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

/**
 * 搜尋範圍（A5）：`map`（首頁預設，目前地圖視角）／`city`（目前選的城市）／
 * `all`（不限城市）。網址是真相來源，見 useSearchScope 的說明。
 *
 * **這裡不再有「打了關鍵字就自動不限範圍」的特例**——那是這一批要拿掉的東西：
 * 範圍原本首頁「不限目前範圍」、列表頁「跨全部城市」兩頁不一樣、使用者改不了，
 * 現在兩頁共用同一顆看得到、按得到的控制項，由使用者自己決定。
 */
const scope = useSearchScope('map');

/**
 * `scope=all` 完全不能送座標：後端在沒有 bbox 時，lat/lng 會套上預設 5km 半徑
 * （`RestaurantRepository::search()`），把「不限城市」悄悄變回「原地」。
 * 有 bbox（`city`／`map`）時座標不受這個限制——矩形本身就是邊界，一起送
 * 只是多算一個 distance 欄位——可以放心用來排序、也讓卡片顯示距離。
 */
function scopeBbox(): string | undefined {
    if (scope.value === 'all') return undefined;
    if (scope.value === 'city') return activeCity.value?.bbox;

    return currentBounds ? formatBbox(currentBounds) : undefined;
}

function scopeCenter(bboxValue: string | undefined): { lat: number; lng: number } | null {
    if (!bboxValue) return null;

    if (scope.value === 'city' && activeCity.value) {
        return { lat: activeCity.value.center[0], lng: activeCity.value.center[1] };
    }

    if (!currentBounds) return null;

    return {
        lat: (currentBounds.minLat + currentBounds.maxLat) / 2,
        lng: (currentBounds.minLng + currentBounds.maxLng) / 2,
    };
}

async function loadByBounds() {
    if (!currentBounds) return;

    const seq = ++requestSeq;
    loading.value = true;
    loadFailed.value = false;
    invalidFilters.value = false;

    try {
        // 「推薦餐廳」是附近推薦，故意跟搜尋範圍脫鉤，一律看目前地圖視角——
        // 選了「全部城市」不代表使用者想看東京的推薦。
        const midLat = (currentBounds.minLat + currentBounds.maxLat) / 2;
        const midLng = (currentBounds.minLng + currentBounds.maxLng) / 2;
        const viewportBbox = formatBbox(currentBounds);

        const bboxValue = scopeBbox();
        const center = scopeCenter(bboxValue);
        const filterParams = apiFilterParams(filters.value);

        const [restaurantsResult, recommendedResult] = await Promise.allSettled([
            client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
                params: keyword.value
                    ? {
                        keyword: keyword.value,
                        bbox: bboxValue,
                        latitude: center?.lat,
                        longitude: center?.lng,
                        sort: 'relevance',
                        per_page: 100,
                        ...filterParams,
                    }
                    : {
                        bbox: bboxValue,
                        latitude: center?.lat,
                        longitude: center?.lng,
                        // 沒有座標（scope=all）時不送 sort，後端自己退回 newest；
                        // 硬送 sort=distance 但沒座標會被後端當成請求缺 latitude/longitude，回 422。
                        sort: center ? 'distance' : undefined,
                        per_page: 100,
                        ...filterParams,
                    },
            }),
            // 後端 RuleBasedRecommendationService 依 distance/rating/vegetarian_confidence/
            // feature_match/popularity/freshness 加權排序（見總體規劃第三十節），不是單純
            // 依評分排序，所以是獨立一支 API，不是從上面那批結果在前端隨便切幾筆。
            client.get<ApiSuccess<Restaurant[]>>('/restaurants/recommended', {
                params: {
                    bbox: viewportBbox,
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
            // 422 代表條件本身不合法（網址被改過、或貼了舊連結），跟連線失敗
            // 不一樣——後者才值得「移動地圖重試」。
            invalidFilters.value = isAxiosError(restaurantsResult.reason)
                && restaurantsResult.reason.response?.status === 422;
            loadFailed.value = ! invalidFilters.value;
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

/**
 * 一次清掉所有可能造成 422 的東西。使用者不知道是哪一個條件不合法，
 * 逐項猜對他沒有意義。城市留著——那是「我在看哪裡」，不是搜尋條件。
 */
function clearAllFilters() {
    const query = activeCity.value ? { city: activeCity.value.slug } : {};
    router.push({ query });
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

watch(scope, loadByBounds);

watch(activeCity, (city, previous) => {
    if (!city) return;

    rememberCity(city.slug);

    // 第一次是地圖自己用 :center 開好的，不用再飛一次（會多打一輪 API）。
    if (!previous) return;
    if (city.slug === previous.slug) return;

    mapRef.value?.jumpTo(city.center[0], city.center[1], city.zoom);
});

const hasResults = computed(() => restaurants.value.length > 0);

const SCOPE_LABELS: Record<SearchScope, string> = {
    map: '目前地圖範圍',
    city: '這座城市',
    all: '全部城市',
};

/** 給徽章文字用的、比選單裡再具體一點的講法（city 講出城市名，而不是「這座城市」）。 */
const scopeResultLabel = computed(() => {
    if (scope.value === 'city') return activeCity.value?.label ?? SCOPE_LABELS.city;
    if (scope.value === 'map') return '這個範圍';

    return SCOPE_LABELS.all;
});

/**
 * 地圖上真的有灰色 marker 嗎？`RestaurantMap` 在 `venue_kind` 缺席時會畫第三種
 * 灰點，圖例只在那時候才列出它——實測現況一家都不會（見圖例那段註解），
 * 無條件列出只是雜訊。
 */
const hasUnknownKind = computed(() => restaurants.value.some((restaurant) => !restaurant.venue_kind));
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
                <!--
                  搜尋範圍（A5）：原本「打了關鍵字就不限範圍」是藏在程式邏輯裡的特例，
                  使用者改不了。現在是這顆看得到的選單，網址是真相來源
                  （見 useSearchScope），重新整理、分享連結都對得起來。
                -->
                <ScopeSelect v-model="scope" :options="SEARCH_SCOPES" />
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

            <!--
              沒有圖例的顏色編碼等於猜謎。實心綠＝整間都能吃，空心橘＝葷素都有、
              菜單有無肉選項；形狀也不同，不是只靠顏色（色覺辨識有困難的人也分得出來）。
            -->
            <ul class="map-legend" aria-label="地圖圖例">
                <li><span class="veggie-marker" data-kind="exclusive" aria-hidden="true"></span>純素食店</li>
                <li><span class="veggie-marker" data-kind="friendly" aria-hidden="true"></span>素食友善</li>
                <!--
                    第三種灰色 marker：`RestaurantMap` 的 markerIcon 在 venue_kind
                    缺席時會畫它，圖例原本沒解釋——地圖上會出現圖例沒有的顏色。

                    但**只在真的有灰點時才列**：2026-09-06 實測 1167 家 active 餐廳
                    全部是 exclusive(576)／friendly(591)，一家都不會產生灰點，
                    無條件列出等於解釋一個使用者永遠看不到的東西。它是給
                    「diet_types 沒載到或新增了對應不到 kind 的 code」那天用的。
                -->
                <li v-if="hasUnknownKind">
                    <span class="veggie-marker" data-kind="unknown" aria-hidden="true"></span>素食資訊待確認
                </li>
            </ul>

            <p v-if="loading" class="map-badge" role="status">載入中…</p>
            <p v-else-if="invalidFilters" class="map-badge error" role="alert">
                這組搜尋條件無效（可能是網址被改過）。
                <button type="button" class="inline-clear" @click="clearAllFilters">清除條件</button>
            </p>
            <p v-else-if="loadFailed" class="map-badge error" role="alert">
                載入失敗，移動地圖可重新嘗試。
            </p>
            <p v-else-if="hasResults" class="map-badge" role="status">
                <template v-if="keyword">符合「{{ keyword }}」的有 </template>
                {{ restaurants.length }}{{ hasMore ? '+' : '' }} 家（{{ scopeResultLabel }}）
            </p>
        </section>

        <section v-if="showEmptyState" class="empty-state">
            <p class="empty-title">
                <template v-if="keyword">找不到符合「{{ keyword }}」的餐廳</template>
                <template v-else>這個範圍還沒有素食餐廳</template>
            </p>
            <p class="empty-hint">
                <template v-if="keyword">
                    這個關鍵字在{{ scopeResultLabel }}都沒有結果——換個說法，
                    <template v-if="scope !== 'all'">試試「範圍」改選全部城市，或</template>
                    清掉關鍵字回到地圖瀏覽。
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
                        <!--
                            三段標籤而不是裸分數，跟列表卡片同一套說法：同一個產品
                            對同一件事有兩種講法（一邊「素食可信度 5」、一邊
                            「素食資訊待確認」）比不改更糟。理由見 config/vegetarian.php。
                        -->
                        <span
                            v-if="restaurant.confidence_level"
                            class="confidence"
                            :data-level="restaurant.confidence_level.code"
                        >{{ restaurant.confidence_level.label }}</span>
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
    background: var(--vm-green-50);
}

.hero h1 {
    margin: 0;
    color: var(--vm-green-600);
}

.tagline {
    margin: 0.25rem 0 1rem;
    color: var(--vm-ink-600);
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
    border: 1px solid var(--vm-ink-300);
    border-radius: var(--vm-radius-md);
    background: var(--vm-white);
    cursor: pointer;
    white-space: nowrap;
}

.locate-error {
    margin: 0.5rem 0 0;
    color: var(--vm-red-600);
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
    background: var(--vm-ink-50);
    color: var(--vm-ink-500);
}

.map-badge {
    position: absolute;
    top: 0.5rem;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    background: var(--vm-white);
    padding: 0.3rem 0.85rem;
    border-radius: var(--vm-radius-full);
    border: 1px solid var(--vm-ink-200);
    box-shadow: var(--vm-shadow-sm);
    z-index: 1000;
    font-size: 0.85rem;
    white-space: nowrap;
}

.map-badge.error {
    border-color: var(--vm-red-300);
    color: var(--vm-red-600);
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
    color: var(--vm-ink-500);
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
    border: 1px solid var(--vm-ink-200);
    border-radius: var(--vm-radius-lg);
    background: var(--vm-white);
    cursor: pointer;
    text-align: left;
}

.card:hover {
    border-color: var(--vm-green-600);
}

.card .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.card .distance {
    color: var(--vm-green-600);
    font-weight: 600;
}

.card .address {
    color: var(--vm-ink-700);
    font-size: 0.9rem;
}

.cuisines {
    color: var(--vm-green-600);
    font-size: 0.85rem;
}

/*
 * 純素食店＝圓角膠囊、素食友善＝方角標籤（跟列表卡片同一套）。**形狀也不同，
 * 不是只有顏色不同**：綠與藍對紅綠色盲可能是同一個色塊。
 */
.venue-badge {
    align-self: flex-start;
    padding: 0.1rem 0.5rem;
    border-radius: var(--vm-radius-full);
    background: var(--vm-green-50);
    color: var(--vm-green-700);
    border: 1px solid var(--vm-green-200);
    font-size: 0.75rem;
}

.venue-badge[data-kind='friendly'] {
    border-radius: var(--vm-radius-sm);
    background: var(--vm-blue-50);
    color: var(--vm-blue-600);
    border-color: var(--vm-blue-200);
}

.venue-summary {
    color: var(--vm-ink-600);
    font-size: 0.8rem;
}

@media (max-width: 640px) {
    .map-section {
        height: 55vh;
    }
}

.open-status[data-state='open'] {
    color: var(--vm-green-600);
    font-weight: 600;
}

.open-status[data-state='closed'] {
    color: var(--vm-ink-500);
}

.keyword-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0.5rem auto 0;
    padding: 0.3rem 0.75rem;
    border-radius: var(--vm-radius-full);
    background: var(--vm-green-50);
    color: var(--vm-green-800);
    font-size: 0.85rem;
}

.keyword-badge button {
    border: none;
    background: none;
    color: var(--vm-green-600);
    cursor: pointer;
    text-decoration: underline;
    font-size: 0.85rem;
}

.confidence {
    color: var(--vm-blue-700);
    font-size: 0.85rem;
}

.map-legend {
    position: absolute;
    left: 0.75rem;
    /*
     * 抬高到 Leaflet 的著作權標示上方。實測 375×812 時兩者重疊——OSM 的授權
     * 要求那行必須看得見，蓋住它不只是版面問題。
     */
    bottom: 1.75rem;
    z-index: 500;
    display: flex;
    gap: 0.75rem;
    margin: 0;
    padding: 0.4rem 0.6rem;
    list-style: none;
    background: rgba(255, 255, 255, 0.92);
    border-radius: var(--vm-radius-md);
    font-size: 0.8rem;
    color: var(--vm-ink-700);
    box-shadow: var(--vm-shadow-md);
}

.map-legend li {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.map-legend .veggie-marker {
    width: 12px;
    height: 12px;
    box-shadow: none;
}

.map-legend .veggie-marker[data-kind='friendly'] {
    border-width: 3px;
}

.inline-clear {
    margin-left: 0.4rem;
    border: none;
    background: none;
    color: inherit;
    cursor: pointer;
    text-decoration: underline;
    font-size: inherit;
}
</style>
