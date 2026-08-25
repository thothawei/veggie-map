<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import client from '@/api/client';
import FilterDrawer from '@/components/FilterDrawer.vue';
import CitySwitcher from '@/components/CitySwitcher.vue';
import { ALL_CITIES, useCities } from '@/composables/useCities';
import { apiFilterParams, filterQueryKey, useFilterQuery } from '@/composables/useFilterQuery';
import { formatAddress, formatCuisines, formatOpenStatus } from '@/lib/format';
import type { ApiSuccess, Restaurant } from '@/types';

const router = useRouter();
const route = useRoute();

// 列表頁維持它原本「列出全部」的行為當預設，城市是可選的收窄條件——地圖頁不同，
// 那裡一定得看著某個地方，所以退回第一個城市。
const { cities, loading: citiesLoading, activeCity, activeSlug, selectCity } = useCities({ fallback: 'all' });

const restaurants = ref<Restaurant[]>([]);

/**
 * 輸入框裡的草稿；只有按下搜尋才寫進網址。每打一個字就推一筆歷史紀錄的話，
 * 使用者按上一頁會變成逐字倒退，而不是回到上一次的搜尋結果。
 */
const keywordDraft = ref('');

/** 網址才是「現在正在搜什麼」的真相來源——重新整理、分享連結、上一頁因此都對。 */
const committedKeyword = computed(() => (typeof route.query.keyword === 'string' ? route.query.keyword : ''));

// 篩選條件跟 city／keyword 一樣以網址為真相來源。
const filters = useFilterQuery();
const nextCursor = ref<string | null>(null);
const loading = ref(false);
const loadFailed = ref(false);

/**
 * 城市用 bbox 收窄而不是 `city` 欄位：實測 592 筆匯入資料裡 59% 的 `city` 是空的，
 * 同一個城市還有「臺中市／台中市」兩種寫法，東京的節點填的是「渋谷区」這類行政區。
 * 也不能換算成 latitude+radius——台中半對角線 59.6km、高雄 66.4km，都超過 radius
 * 上限 50km（見 tests/Feature/Api/RestaurantBboxSearchTest.php）。
 */
/**
 * **打了關鍵字就跨全部城市搜尋**（2026-08-25 決定）。原本選了城市就只在那個城市的
 * bbox 內找，使用者搜「Loving Hut」卻只看到台北那幾家，會誤以為其他城市沒有。
 * 城市切換是「瀏覽某個地區」的工具，關鍵字搜尋則是「我知道要找什麼」，不該被地區綁住。
 */
const bbox = computed(() => (committedKeyword.value ? undefined : activeCity.value?.bbox));

/** 有關鍵字時城市限制會被忽略，畫面要講清楚，不能讓人以為還在該城市內找。 */
const searchIsGlobal = computed(() => Boolean(committedKeyword.value) && activeCity.value !== null);

// 同時有「搜尋」「改篩選」「換城市」三個觸發來源，慢的舊請求可能在新請求之後才回來，
// 把畫面蓋回舊資料；載入更多還會把舊的一頁重複接上去。用序號讓過期回應直接丟掉。
let requestSeq = 0;

async function search(reset = true) {
    const seq = ++requestSeq;
    loading.value = true;
    loadFailed.value = false;

    try {
        const response = await client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
            params: {
                keyword: committedKeyword.value || undefined,
                bbox: bbox.value,
                // 有關鍵字就交給後端的相關性排序（店名 > 菜色／料理 > 地區），
                // 寫死 newest 的話，最符合的那家店可能排在第 40 名。
                sort: committedKeyword.value ? 'relevance' : 'newest',
                per_page: 20,
                cursor: reset ? undefined : (nextCursor.value ?? undefined),
                ...apiFilterParams(filters.value),
            },
        });

        if (seq !== requestSeq) return;

        restaurants.value = reset ? response.data.data : [...restaurants.value, ...response.data.data];
        nextCursor.value = (response.data.meta?.next_cursor as string | null) ?? null;
    } catch {
        if (seq !== requestSeq) return;

        loadFailed.value = true;

        if (reset) {
            restaurants.value = [];
            nextCursor.value = null;
        }
    } finally {
        if (seq === requestSeq) {
            loading.value = false;
        }
    }
}

function submitSearch() {
    const next = keywordDraft.value.trim();

    if (next === committedKeyword.value) {
        // 網址沒變就不會觸發 watch，但使用者按了搜尋就該有動作（例如想重新拉一次結果）。
        search(true);

        return;
    }

    router.push({ query: { ...route.query, keyword: next || undefined } });
}

function clearKeyword() {
    const query = { ...route.query };
    delete query.keyword;

    router.push({ query });
}

function goToDetail(restaurant: Restaurant) {
    router.push({ name: 'restaurant-detail', params: { id: restaurant.id } });
}

const scopeLabel = computed(() => (bbox.value ? (activeCity.value?.label ?? '全部城市') : '全部城市'));

const emptyMessage = computed(() => {
    const where = bbox.value ? (activeCity.value?.label ?? '') : '';
    const what = committedKeyword.value ? `符合「${committedKeyword.value}」的餐廳` : '符合條件的餐廳';

    return `${where}沒有${what}。`;
});

// 空結果要給得出下一步，不是只說「沒有」。只列真正適用的建議——沒下關鍵字卻叫人
// 「改個關鍵字」只會讓人困惑。
const emptySuggestions = computed(() => {
    const suggestions: string[] = [];

    if (committedKeyword.value) suggestions.push('換個關鍵字');
    if (hasActiveFilters.value) suggestions.push('清掉篩選條件');
    if (activeCity.value && bbox.value) suggestions.push('切換到其他城市');

    return suggestions;
});
const hasActiveFilters = computed(
    () => Object.values(filters.value).some((value) => value !== undefined && value !== null),
);

/**
 * 城市清單是非同步載入的。若在載入前就先查一次、載入後因為 activeCity 變了再查一次，
 * 等於每次進頁面都白打一發 API（實測真的會送出兩個請求）。改成用一個「查詢範圍」的
 * key 統一觸發：清單還沒載完是 null（不查），載完後變成 bbox 或 ALL_CITIES，
 * 之後只要使用者換城市才會再變。
 */
const searchScope = computed(() => {
    if (citiesLoading.value) return null;

    return JSON.stringify([bbox.value ?? ALL_CITIES, committedKeyword.value, filterQueryKey(filters.value)]);
});

watch(searchScope, (scope) => {
    if (scope !== null) search(true);
}, { immediate: true });

// 上一頁／下一頁或直接改網址時，輸入框要跟著網址走，不然畫面上的字跟結果會對不起來。
watch(committedKeyword, (value) => {
    keywordDraft.value = value;
}, { immediate: true });
</script>

<template>
    <div class="restaurant-list">
        <CitySwitcher
            v-if="cities.length"
            :cities="cities"
            :model-value="activeSlug"
            allow-all
            @update:model-value="selectCity"
        />

        <div class="toolbar">
            <input
                v-model="keywordDraft"
                type="search"
                placeholder="搜尋店名、菜色、料理種類（跨全部城市）"
                @keyup.enter="submitSearch"
            />
            <button type="button" @click="submitSearch">搜尋</button>
            <button v-if="committedKeyword" type="button" class="clear-keyword" @click="clearKeyword">清除</button>
        </div>
        <FilterDrawer v-model:filters="filters" />

        <p v-if="searchIsGlobal" class="global-hint" role="status">
            搜尋「{{ committedKeyword }}」時會跨全部城市，不受目前選的「{{ activeCity?.label }}」限制。
        </p>

        <p v-if="!loading && !loadFailed && restaurants.length" class="scope" role="status">
            {{ scopeLabel }}：{{ restaurants.length }}{{ nextCursor ? '+' : '' }} 家
        </p>

        <ul>
            <li v-for="restaurant in restaurants" :key="restaurant.id">
                <button type="button" @click="goToDetail(restaurant)">
                    <strong>{{ restaurant.name }}</strong>
                    <span
                        v-if="restaurant.venue_badge"
                        class="venue-badge"
                        :data-kind="restaurant.venue_kind ?? undefined"
                    >{{ restaurant.venue_badge }}</span>
                    <span v-if="formatCuisines(restaurant.cuisines)" class="cuisines">{{ formatCuisines(restaurant.cuisines) }}</span>
                    <span v-if="restaurant.venue_summary" class="venue-summary">{{ restaurant.venue_summary }}</span>
                    <span
                        v-if="formatOpenStatus(restaurant)"
                        class="open-status"
                        :data-state="formatOpenStatus(restaurant)?.state"
                    >{{ formatOpenStatus(restaurant)?.text }}</span>
                    <span class="address">{{ formatAddress(restaurant) ?? '地址未提供' }}</span>
                </button>
            </li>
        </ul>

        <p v-if="loadFailed" class="notice error" role="alert">載入失敗，請再試一次。</p>
        <p v-else-if="!loading && restaurants.length === 0" class="notice">
            {{ emptyMessage }}
            <span v-if="emptySuggestions.length">{{ emptySuggestions.join('，或') }}。</span>
        </p>

        <button v-if="nextCursor" type="button" class="more" :disabled="loading" @click="search(false)">
            {{ loading ? '載入中…' : '載入更多' }}
        </button>
    </div>
</template>

<style scoped>
.restaurant-list {
    padding: 1.5rem;
    max-width: 800px;
    margin: 0 auto;
}

.toolbar {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.toolbar input {
    flex: 1;
    padding: 0.5rem 0.75rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
}

.toolbar button {
    padding: 0.5rem 1rem;
    background: #2f855a;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.toolbar .clear-keyword {
    background: #fff;
    color: #2f855a;
    border: 1px solid #cbd5e0;
}

.global-hint {
    margin: 0.75rem 0 0;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    background: #f0fff4;
    color: #2f855a;
    font-size: 0.85rem;
}

.scope {
    margin: 0.75rem 0 0.5rem;
    color: #718096;
    font-size: 0.85rem;
}

ul {
    list-style: none;
    padding: 0;
}

li button {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    width: 100%;
    text-align: left;
    padding: 1rem;
    margin-bottom: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
}

li button:hover {
    border-color: #2f855a;
}

.address {
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

.notice {
    color: #718096;
    text-align: center;
    padding: 1.5rem 0;
}

.notice.error {
    color: #c53030;
}

.more {
    display: block;
    margin: 0 auto;
    padding: 0.5rem 1.25rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
}

.more:disabled {
    opacity: 0.6;
    cursor: default;
}

.open-status[data-state='open'] {
    color: #2f855a;
    font-weight: 600;
}

.open-status[data-state='closed'] {
    color: #718096;
}
</style>
