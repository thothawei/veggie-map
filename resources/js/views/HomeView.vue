<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
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

/**
 * 底部 sheet（B1）：收合時只有一行摘要，展開才看得到清單。**預設收合**——
 * 地圖優先版面的重點就是首屏是地圖，不是清單。
 *
 * 兩種情況自動展開，使用者不用自己點：
 * 1. 打了關鍵字且有結果——這是使用者主動要找特定東西，看得到命中清單
 *    比看地圖上一堆點更直接（跟 fitToKeywordResults() 收視角是同一個理由）。
 * 2. 零結果——空狀態的說明（放寬條件／你是不是要找）本來就該讓人一目了然，
 *    逼他先點開「展開」才看得到理由，等於多一次無意義的點擊。
 *
 * 使用者手動收合／展開之後不會被這兩條規則打回去——那是他的選擇，不是
 * 系統要糾正的狀態。
 */
const sheetExpanded = ref(false);

watch([keyword, hasResults], ([currentKeyword, results]) => {
    if (currentKeyword && results) {
        sheetExpanded.value = true;
    }
});

watch(showEmptyState, (empty) => {
    if (empty) {
        sheetExpanded.value = true;
    }
});

const sheetSummary = computed(() => {
    if (loading.value) return '載入中…';
    if (invalidFilters.value || loadFailed.value) return '無法載入結果';
    if (!hasResults.value) return '目前沒有符合的餐廳';

    const count = `${restaurants.value.length}${hasMore.value ? '+' : ''} 家`;

    return keyword.value ? `符合「${keyword.value}」的有 ${count}` : `${scopeResultLabel.value}有 ${count}`;
});

/* ---------- 地圖 ↔ 清單雙向連動（B2） ---------- */

/**
 * 卡片的 DOM 節點，點 marker 時要捲到它。用一般的 Map 而不是 ref 陣列——
 * 這只是查表用的，不需要觸發重新渲染。清單換了（換城市、改篩選、重新搜尋）
 * 就整批清掉，不然舊的節點引用會一直留著、越積越多。
 */
const cardRefs = new Map<number, HTMLElement>();

watch(restaurants, () => {
    cardRefs.clear();
});

/** 目前捲過去、要標成醒目的那一張卡；換一個目標或收合 sheet 就清掉。 */
const highlightedRestaurantId = ref<number | null>(null);

/**
 * 點 marker＝清單捲過去給他看＋醒目標示，不是直接跳轉詳情頁——那是「看詳情」
 * 按鈕的事（見 RestaurantMap 的 marker-focused 事件註解）。
 */
function handleMarkerFocused(restaurant: Restaurant) {
    sheetExpanded.value = true;
    highlightedRestaurantId.value = restaurant.id;

    void nextTick(() => {
        // `scrollIntoView` 用可選呼叫——jsdom 沒有這個方法，測試環境呼叫會直接爆
        // （SearchBox 的 scrollActiveIntoView() 已經踩過同一個坑）。
        cardRefs.get(restaurant.id)?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
    });
}

watch(sheetExpanded, (expanded) => {
    if (!expanded) {
        highlightedRestaurantId.value = null;
    }
});
</script>

<template>
    <div class="home">
        <!--
          地圖優先版面（B1）：地圖佔滿可視區，控制項浮在地圖上，不再疊在
          地圖上方把它推下去。「VeggieMap／找到適合你的素食餐廳」的品牌標題
          移除——使用者已經在站上了，品牌留在 header 就夠，一個地圖產品的
          首屏應該是地圖。
        -->
        <section class="map-shell">
            <div class="top-bar">
                <CitySwitcher
                    v-if="cities.length"
                    :cities="cities"
                    :model-value="activeCity?.slug ?? null"
                    @update:model-value="selectCity"
                />

                <div class="top-bar-controls">
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
                </div>
                <p v-if="locateError" class="locate-error" role="alert">{{ locateError }}</p>
                <p v-if="keyword" class="keyword-badge" role="status">
                    只顯示符合「{{ keyword }}」的餐廳
                    <button type="button" @click="clearKeyword">清除</button>
                </p>
                <FilterDrawer v-model:filters="filters" :result-count="restaurants.length" :has-more-results="hasMore" />
            </div>

            <RestaurantMap
                v-if="activeCity"
                ref="mapRef"
                class="map-fill"
                :restaurants="restaurants"
                :center="activeCity.center"
                :zoom="activeCity.zoom"
                @bounds-changed="handleBoundsChanged"
                @select="goToDetail"
                @locate-failed="handleLocateFailed"
                @marker-focused="handleMarkerFocused"
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

            <!--
              地圖上的淡遮罩＋spinner（B5）。取代原本什麼都不顯示的空窗期——
              地圖本身還在（marker 是舊的沒關係，下面的 `.map-badge` 已經另外
              顯示「載入中…」），遮罩只是讓使用者知道畫面正在動、不是卡住了。
            -->
            <div v-if="loading" class="map-loading-overlay" aria-busy="true" aria-label="地圖資料載入中">
                <span class="spinner" aria-hidden="true"></span>
            </div>

            <!-- 角落控制項：定位鈕移到這裡，跟圖例分居地圖左右下角。 -->
            <button type="button" class="locate-button" @click="handleLocate">📍 使用目前位置</button>

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

            <!--
              底部 sheet（B1）：收合時只有一行摘要，展開才看得到清單。地圖與
              清單的連動（滑到卡片放大 marker、點 marker 捲到卡片）是 B2 的
              範圍，這裡先把清單本身做出來——B2 才有東西可以連動。
            -->
            <section class="result-sheet" :class="{ expanded: sheetExpanded }">
                <button
                    type="button"
                    class="sheet-toggle"
                    :aria-expanded="sheetExpanded"
                    aria-controls="result-sheet-body"
                    @click="sheetExpanded = !sheetExpanded"
                >
                    <span class="sheet-summary">{{ sheetSummary }}</span>
                    <span class="chevron" aria-hidden="true">{{ sheetExpanded ? '收合 ▴' : '展開 ▾' }}</span>
                </button>

                <div id="result-sheet-body" class="sheet-body" :hidden="!sheetExpanded">
                    <!-- 載入 skeleton（B5）：只在還沒有任何結果可以顯示時才蓋掉整片，
                         已經有舊結果、正在重查時讓舊清單留著，不要每次都閃一次空白。 -->
                    <div v-if="loading && !hasResults" class="cards" aria-busy="true" aria-label="正在載入餐廳清單">
                        <div v-for="n in 3" :key="n" class="result-card skeleton-card" aria-hidden="true">
                            <span class="skeleton-line skeleton-title"></span>
                            <span class="skeleton-line skeleton-badge"></span>
                            <span class="skeleton-line skeleton-text"></span>
                        </div>
                    </div>

                    <section v-else-if="showEmptyState" class="empty-state">
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

                    <div v-else-if="hasResults" class="cards">
                        <button
                            v-for="restaurant in restaurants"
                            :key="restaurant.id"
                            :ref="(el) => { if (el) cardRefs.set(restaurant.id, el as HTMLElement); }"
                            type="button"
                            class="result-card"
                            :class="{ highlighted: highlightedRestaurantId === restaurant.id }"
                            @click="goToDetail(restaurant)"
                            @mouseenter="mapRef?.highlightRestaurant(restaurant.id)"
                            @mouseleave="mapRef?.highlightRestaurant(null)"
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
                </div>
            </section>
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
/*
 * 地圖優先版面（B1）。`.map-shell` 是唯一的定位錨點——`.top-bar`／
 * `.map-legend`／locate 按鈕／`.map-badge`／`.result-sheet` 全部絕對定位在
 * 它上面，`.map-fill` 用 inset:0 把 RestaurantMap 撐滿整個殼。
 *
 * 高度用「視窗高減去大概的 header 高」，不是精算值——header 是 App.vue 管的
 * flex-wrap 版面，這一輪不動它，這裡只能給一個看起來對的估計值。
 */
.map-shell {
    position: relative;
    height: calc(100vh - 64px);
    min-height: 520px;
    overflow: hidden;
}

.map-fill {
    position: absolute;
    inset: 0;
}

.map-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--vm-ink-50);
    color: var(--vm-ink-500);
}

/* 地圖上的淡遮罩＋spinner（B5）。淡到還看得見底下的地圖與 marker——
   使用者要知道的是「正在動」，不是「畫面被蓋住了」。 */
.map-loading-overlay {
    position: absolute;
    inset: 0;
    z-index: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.35);
    pointer-events: none;
}

.spinner {
    width: 2rem;
    height: 2rem;
    border: 3px solid var(--vm-ink-200);
    border-top-color: var(--vm-green-600);
    border-radius: 50%;
    animation: spinner-spin 0.7s linear infinite;
}

@keyframes spinner-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .spinner {
        animation: none;
    }
}

/*
 * 載入 skeleton（B5）。跟 RestaurantListView 那份是同樣的視覺語言，但兩邊
 * 是各自 scoped 的 style，沒有共用檔案——這五個檔案的改動範圍本來就不含
 * 抽一個共用元件出來，重複這幾條規則比新增一個共用檔案的風險小。
 */
.skeleton-card {
    gap: 0.5rem;
    cursor: default;
}

.skeleton-line {
    display: block;
    height: 0.9rem;
    border-radius: var(--vm-radius-sm);
    background: linear-gradient(90deg, var(--vm-ink-100) 25%, var(--vm-ink-200) 37%, var(--vm-ink-100) 63%);
    background-size: 400% 100%;
    animation: skeleton-shimmer 1.4s ease infinite;
}

.skeleton-title {
    width: 60%;
}

.skeleton-badge {
    width: 30%;
    height: 0.75rem;
}

.skeleton-text {
    width: 85%;
}

@keyframes skeleton-shimmer {
    0% {
        background-position: 100% 50%;
    }

    100% {
        background-position: 0 50%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .skeleton-line {
        animation: none;
    }
}

/*
 * 浮動列：固定在頂端、半透明底＋陰影，蓋在地圖上而不是把地圖推下去。
 * 展開篩選面板時會自己長高——它是 in-flow 的內容，只有這個容器本身用
 * absolute 貼在頂端，長高只會往下蓋住更多地圖，不會把版面撐開。
 */
.top-bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    padding: 0.75rem 1rem;
    background: rgba(255, 255, 255, 0.94);
    box-shadow: var(--vm-shadow-md);
}

.top-bar-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    max-width: 640px;
    margin: 0.5rem auto 0;
    align-items: flex-start;
}

.top-bar-controls .search-box {
    flex: 1;
    min-width: 200px;
}

/*
 * 定位鈕改成角落控制項，跟圖例分居地圖左右下角，不再擠在浮動列裡。
 * bottom 抬高到底部 sheet 收合列（約 3rem）之上，不然會被蓋住——
 * 桌機版 sheet 搬到左側之後這裡改回貼底。
 */
.locate-button {
    position: absolute;
    right: 0.75rem;
    bottom: 5rem;
    z-index: 500;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--vm-ink-300);
    border-radius: var(--vm-radius-md);
    background: rgba(255, 255, 255, 0.92);
    box-shadow: var(--vm-shadow-md);
    cursor: pointer;
    white-space: nowrap;
}

.locate-error {
    margin: 0.5rem 0 0;
    color: var(--vm-red-600);
    font-size: 0.9rem;
}

.map-badge {
    position: absolute;
    /* 浮動列的高度會變（篩選面板展開／關鍵字徽章出現），這裡只能給一個
       在大多數狀態下都不會被蓋住的估計值。 */
    top: 8.5rem;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    background: var(--vm-white);
    padding: 0.3rem 0.85rem;
    border-radius: var(--vm-radius-full);
    border: 1px solid var(--vm-ink-200);
    box-shadow: var(--vm-shadow-sm);
    z-index: 900;
    font-size: 0.85rem;
    white-space: nowrap;
}

.map-badge.error {
    border-color: var(--vm-red-300);
    color: var(--vm-red-600);
}

/*
 * 底部 sheet。收合時只有 `.sheet-toggle` 那一行；展開時 `.sheet-body` 用
 * `hidden` 屬性控制（不是 `display` 內嵌樣式），桌機版直接無視收合狀態
 * 常駐顯示——見下面 `min-width: 900px` 那段。
 */
.result-sheet {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 800;
    max-height: 70%;
    display: flex;
    flex-direction: column;
    background: var(--vm-white);
    border-top-left-radius: var(--vm-radius-lg);
    border-top-right-radius: var(--vm-radius-lg);
    box-shadow: var(--vm-shadow-md);
}

.sheet-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border: none;
    background: none;
    cursor: pointer;
    font: inherit;
    text-align: left;
}

.sheet-summary {
    font-weight: 600;
}

.chevron {
    color: var(--vm-ink-500);
    font-size: 0.85rem;
    white-space: nowrap;
}

.sheet-body {
    overflow-y: auto;
    padding: 0 1rem 1rem;
}

.empty-state {
    padding: 1rem 0;
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

.card,
.result-card {
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

.result-card {
    width: 100%;
    margin-bottom: 0.75rem;
}

.card:hover,
.result-card:hover {
    border-color: var(--vm-green-600);
}

/* 點了地圖上的 marker 之後捲過來的那一張卡（B2）。 */
.result-card.highlighted {
    border-color: var(--vm-green-600);
    background: var(--vm-green-50);
    box-shadow: 0 0 0 2px var(--vm-green-200);
}

.card .meta,
.result-card .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.card .distance,
.result-card .distance {
    color: var(--vm-green-600);
    font-weight: 600;
}

.card .address,
.result-card .address {
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
     * 抬高到底部 sheet 收合列（約 3rem）之上——不然會被蓋住。原本抬高到
     * Leaflet 著作權標示上方的理由還在（OSM 授權要求那行要看得見），
     * sheet 的高度剛好比它高，一次抬夠兩者都不會被蓋住。
     */
    bottom: 5rem;
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

/*
 * 桌機：底部 sheet 變成常駐的左側清單，跟 RestaurantMap 兩欄並排——不是
 * 拿 JS 判斷視窗寬度，`hidden` 屬性在這個斷點被 `!important` 蓋掉即可。
 *
 * **這個 media query 必須放在檔案最後**：CSS 同specificity 時後面的規則贏，
 * 這裡要覆蓋的 `.map-legend`／`.locate-button`／`.result-sheet` 等規則散落在
 * 檔案前面各處，放前面會被後面那些同 specificity 的基礎規則蓋回去
 * ——2026-09-06 實測踩過，桌機版 `.map-legend` 的 `left:380px` 完全沒生效，
 * 因為當時這個區塊寫在 `.map-legend` 基礎規則之前。
 */
@media (min-width: 900px) {
    .result-sheet {
        top: 8.5rem;
        left: 0;
        right: auto;
        bottom: 0;
        width: 360px;
        max-height: none;
        border-top-right-radius: 0;
        border-bottom-left-radius: 0;
    }

    .sheet-toggle {
        display: none;
    }

    .sheet-body {
        display: block !important;
        height: 100%;
    }

    /* sheet 搬到左側之後不再蓋住地圖底部，圖例／定位鈕回到貼底的位置。 */
    .map-legend,
    .locate-button {
        bottom: 1.75rem;
    }

    /* 圖例原本貼在最左邊，現在那個位置被左側清單佔走，往右挪到清單外面。 */
    .map-legend {
        left: 380px;
    }
}
</style>
