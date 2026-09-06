<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import { useRoute, useRouter } from 'vue-router';
import client from '@/api/client';
import FilterDrawer from '@/components/FilterDrawer.vue';
import CitySwitcher from '@/components/CitySwitcher.vue';
import ScopeSelect from '@/components/ScopeSelect.vue';
import { ALL_CITIES, useCities } from '@/composables/useCities';
import { useSearchScope } from '@/composables/useSearchScope';
import { apiFilterParams, filterQueryKey, useFilterQuery } from '@/composables/useFilterQuery';
import { formatAddress, formatCuisines, formatMatchReasons, formatOpenStatus } from '@/lib/format';
import { googleMapsUrl } from '@/lib/geo';
import type { ApiSuccess, DidYouMean, ExpandedTerm, Relaxation, Restaurant } from '@/types';

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

/**
 * 只搜原詞、不展開同義詞。跟 keyword 一樣寫進網址，否則重新整理或分享連結時，
 * 畫面說「只搜『麵包』」但結果其實是展開過的——那比不做這個開關更糟。
 */
const exactMode = computed(() => route.query.exact === '1');

/** 後端說「這次一併搜了哪些同義詞」。沒有展開時後端不回這個 key。 */
const expandedTerms = ref<ExpandedTerm[]>([]);

/**
 * 零結果時後端算好的「放寬哪一個條件會有幾家」。空狀態把它們渲染成可按的按鈕——
 * 使用者當下的問題不是「沒有店」而是「我做錯了什麼」，四個篩選任一個都可能是兇手。
 */
const relaxations = ref<Relaxation[]>([]);

/**
 * 「你是不是要找…」。同樣只在 0 筆時後端才回，而且**不自動改寫查詢**——
 * 點下去才會換成那個詞，使用者因此永遠知道自己看的是哪一個查詢的結果。
 */
const didYouMean = ref<DidYouMean[]>([]);

/**
 * 按下放寬按鈕＝把那個條件從網址拿掉（或改成後端指定的值）。
 *
 * `bbox` 要特別翻譯：列表頁的 bbox 是從 `?city=` 算出來的，網址上沒有 bbox 這個
 * 參數，刪它不會有任何效果——要刪的是 city。
 */
function applyRelaxation(relaxation: Relaxation) {
    const query = { ...route.query };

    if (relaxation.param === 'bbox') {
        delete query.city;
    } else if (relaxation.value === null) {
        delete query[relaxation.param];
    } else {
        query[relaxation.param] = relaxation.value;
    }

    router.push({ query });
}

/** 搜這個變體：把它換成新的關鍵字，並離開 exact 模式（使用者主動挑了一個詞）。 */
function searchVariant(variant: string) {
    const query = { ...route.query };
    query.keyword = variant;
    delete query.exact;

    router.push({ query });
}

function setExact(on: boolean) {
    const query = { ...route.query };

    if (on) {
        query.exact = '1';
    } else {
        delete query.exact;
    }

    router.push({ query });
}

/**
 * 排序選項。
 *
 * 沒有 `distance`：列表頁用 bbox 收窄而不是中心點＋半徑（見下方 bbox 的說明），
 * 沒有中心點就算不出距離，後端也會回 422。地圖頁才有距離排序。
 *
 * `relevance` 只在有關鍵字時出現——沒有關鍵字時它沒有意義，後端同樣回 422。
 *
 * **沒有「評分」**：這個產品不做評分評論制度（2026-08-26 產品決定），1159 筆
 * 匯入資料裡只有 1 筆有評分。開一個永遠等於「隨機排序」的選項比不開更糟——
 * 使用者會以為自己排過了。後端的 `sort=rating` 保留給有評分資料的使用端。
 */
const SORT_OPTIONS = [
    { value: 'relevance', label: '相關性', needsKeyword: true },
    { value: 'confidence', label: '素食可信度', needsKeyword: false },
    { value: 'newest', label: '最新收錄', needsKeyword: false },
] as const;

type SortValue = (typeof SORT_OPTIONS)[number]['value'];

const availableSorts = computed(() =>
    SORT_OPTIONS.filter((option) => !option.needsKeyword || committedKeyword.value !== ''),
);

/** 沒指定就跟後端同一套預設：有關鍵字＝相關性，否則最新收錄。 */
const defaultSort = computed<SortValue>(() => (committedKeyword.value ? 'relevance' : 'newest'));

const sort = computed<SortValue>(() => {
    const fromUrl = route.query.sort;
    const isAvailable = availableSorts.value.some((option) => option.value === fromUrl);

    // 網址上帶了一個當下不可用的排序（例如把 relevance 的連結分享出去、對方沒有
    // 關鍵字），退回預設而不是原封不動送出去讓後端回 422 變成「載入失敗」。
    return isAvailable ? (fromUrl as SortValue) : defaultSort.value;
});

/**
 * 一次清掉所有可能造成 422 的東西：篩選、關鍵字、排序。使用者不知道是哪一個
 * 條件不合法（他可能只是貼了一個舊連結），逐項猜對他沒有意義。
 */
function clearAll() {
    keywordDraft.value = '';
    router.push({ query: activeSlug.value ? { city: activeSlug.value } : {} });
}

function selectSort(value: string) {
    const query = { ...route.query };

    if (value === defaultSort.value) {
        delete query.sort;
    } else {
        query.sort = value;
    }

    router.push({ query });
}

// 篩選條件跟 city／keyword 一樣以網址為真相來源。
const filters = useFilterQuery();
const nextCursor = ref<string | null>(null);
const loading = ref(false);
const loadFailed = ref(false);

/** 條件本身不合法（422），跟連線失敗要分開講——後者才值得「再試一次」。 */
const invalidFilters = ref(false);

/**
 * 城市用 bbox 收窄而不是 `city` 欄位：實測 592 筆匯入資料裡 59% 的 `city` 是空的，
 * 同一個城市還有「臺中市／台中市」兩種寫法，東京的節點填的是「渋谷区」這類行政區。
 * 也不能換算成 latitude+radius——台中半對角線 59.6km、高雄 66.4km，都超過 radius
 * 上限 50km（見 tests/Feature/Api/RestaurantBboxSearchTest.php）。
 */

/**
 * 搜尋範圍（A5）：`city`（列表頁預設，目前選的城市）／`all`（不限城市），跟首頁
 * 共用同一個 `?scope=`（見 useSearchScope）。這裡沒有 `map` 可選——列表頁沒有
 * 地圖視角——網址上出現 `scope=map`（多半是從首頁分享連結貼過來）時退回
 * 跟 `city` 一樣的處理，而不是報錯或忽略整個網址。
 *
 * **這裡不再有「打了關鍵字就跨全部城市」的特例**（2026-08-25 的決定，這一批拿掉）：
 * 原本無論選了哪個城市，一打關鍵字就強制忽略、永遠跨全部城市，使用者改不了
 * 也不知道發生了什麼。現在由這顆看得到的選單決定，預設維持城市——跟原本
 * 「沒有關鍵字」時的行為一致，要跨全部城市自己選。
 */
const scope = useSearchScope('city');

const bbox = computed(() => (scope.value === 'all' ? undefined : activeCity.value?.bbox));

/** 選了特定城市、但範圍調成「全部城市」時要講清楚，不能讓人以為還在該城市內找。 */
const searchIsGlobal = computed(() => scope.value === 'all' && activeCity.value !== null);

// 同時有「搜尋」「改篩選」「換城市」三個觸發來源，慢的舊請求可能在新請求之後才回來，
// 把畫面蓋回舊資料；載入更多還會把舊的一頁重複接上去。用序號讓過期回應直接丟掉。
let requestSeq = 0;

async function search(reset = true) {
    const seq = ++requestSeq;
    loading.value = true;
    loadFailed.value = false;
    invalidFilters.value = false;

    try {
        const response = await client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
            params: {
                keyword: committedKeyword.value || undefined,
                exact: exactMode.value ? 1 : undefined,
                bbox: bbox.value,
                sort: sort.value,
                per_page: 20,
                cursor: reset ? undefined : (nextCursor.value ?? undefined),
                ...apiFilterParams(filters.value),
            },
        });

        if (seq !== requestSeq) return;

        restaurants.value = reset ? response.data.data : [...restaurants.value, ...response.data.data];
        nextCursor.value = (response.data.meta?.next_cursor as string | null) ?? null;
        expandedTerms.value = (response.data.meta?.expanded_terms as ExpandedTerm[] | undefined) ?? [];
        relaxations.value = (response.data.meta?.relaxations as Relaxation[] | undefined) ?? [];
        didYouMean.value = (response.data.meta?.did_you_mean as DidYouMean[] | undefined) ?? [];
    } catch (error: unknown) {
        if (seq !== requestSeq) return;

        // 422 代表送出去的條件本身不合法（例如使用者把網址的 `?diet=` 改成不存在
        // 的值）。跟「網路壞了」不一樣：叫他「再試一次」再試一百次也一樣。
        invalidFilters.value = isAxiosError(error) && error.response?.status === 422;
        loadFailed.value = ! invalidFilters.value;

        if (reset) {
            restaurants.value = [];
            nextCursor.value = null;
            expandedTerms.value = [];
            relaxations.value = [];
            didYouMean.value = [];
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
    // exact 是「這個關鍵字不要展開」的修飾詞，關鍵字沒了它就沒有意義，
    // 留著只會在下一次搜尋時悄悄生效。
    delete query.exact;

    router.push({ query });
}

function goToDetail(restaurant: Restaurant) {
    // slug 優先：網址看得懂是規劃第二十六節的目的。沒有 slug（建議清單只回四個
    // 欄位）就退回 id，後端兩種都收。
    router.push({ name: 'restaurant-detail', params: { id: restaurant.slug ?? restaurant.id } });
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
    // 這兩個條件最常把結果篩成 0，而且使用者未必記得自己開了：「營業中」在深夜
    // 幾乎會清空整份清單，可信度門檻則會濾掉所有還沒有人查證過的店。
    if (filters.value.open_now) suggestions.push('關掉「營業中」（很多店家沒有營業時間資料）');
    // 「降低門檻」不夠——使用者不知道為什麼一家都沒有。OSM 匯入的店只有外部
    // 資料來源那 5～10 分的基礎分，門檻 30 以上要有人工查證才達得到。
    if (filters.value.confidence_min) {
        suggestions.push('降低素食可信度門檻（多數餐廳目前只有外部資料來源的基礎分，還沒有人工查證）');
    }
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

    return JSON.stringify([
        bbox.value ?? ALL_CITIES,
        committedKeyword.value,
        exactMode.value,
        sort.value,
        filterQueryKey(filters.value),
        scope.value,
    ]);
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
                placeholder="搜尋店名、菜色、料理種類"
                @keyup.enter="submitSearch"
            />
            <button type="button" @click="submitSearch">搜尋</button>
            <button v-if="committedKeyword" type="button" class="clear-keyword" @click="clearKeyword">清除</button>
            <!--
              搜尋範圍（A5）：原本「打了關鍵字就跨全部城市」是藏在程式邏輯裡的特例，
              使用者改不了。現在是這顆看得到的選單，網址是真相來源
              （見 useSearchScope），重新整理、分享連結都對得起來。列表頁沒有地圖，
              所以沒有 `map` 選項。
            -->
            <ScopeSelect v-model="scope" :options="['city', 'all']" />
        </div>
        <FilterDrawer v-model:filters="filters" />

        <div class="sort-bar">
            <label for="sort-select">排序</label>
            <select id="sort-select" :value="sort" @change="selectSort(($event.target as HTMLSelectElement).value)">
                <option v-for="option in availableSorts" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </div>

        <!--
            展開是這個專案搜尋能力最有價值的一塊，但沉默的時候，使用者搜「珍珠奶茶」
            看到一家叫「綠意茶飲」的店排第一只會覺得搜尋不準。說出來，它就從
            「怪怪的」變成「原來它懂」；旁邊的「只搜…」則是展開幫倒忙時的逃生門。
        -->
        <p v-if="expandedTerms.length" class="expanded-terms" role="status">
            <span v-for="(entry, index) in expandedTerms" :key="entry.term">
                <template v-if="index > 0">；</template>
                「{{ entry.term }}」也一併搜尋了：
                <button
                    v-for="variant in entry.variants"
                    :key="variant"
                    type="button"
                    class="variant"
                    @click="searchVariant(variant)"
                >{{ variant }}</button>
            </span>
            <button type="button" class="exact-toggle" @click="setExact(true)">
                只搜「{{ committedKeyword }}」
            </button>
        </p>

        <p v-else-if="exactMode && committedKeyword" class="expanded-terms" role="status">
            只搜「{{ committedKeyword }}」，沒有一併搜尋同義詞。
            <button type="button" class="exact-toggle" @click="setExact(false)">也搜同義詞</button>
        </p>

        <p v-if="searchIsGlobal" class="global-hint" role="status">
            範圍是全部城市，不受目前選的「{{ activeCity?.label }}」限制。
        </p>

        <p v-if="!loading && !loadFailed && restaurants.length" class="scope" role="status">
            {{ scopeLabel }}：{{ restaurants.length }}{{ nextCursor ? '+' : '' }} 家
        </p>

        <ul>
            <li v-for="restaurant in restaurants" :key="restaurant.id">
                <!--
                    三層資訊，不是把所有欄位平鋪成同一層的 <span>（那樣每一項都
                    一樣重，使用者得自己讀完才知道哪個重要）：
                      1. 身分——店名 ＋ venue 徽章
                      2. 事實——營業狀態 · 料理種類
                      3. 證據——命中原因 · 可信度 · 地址
                -->
                <button type="button" @click="goToDetail(restaurant)">
                    <span class="card-identity">
                        <strong>{{ restaurant.name }}</strong>
                        <!--
                            徽章的形狀也不同（純素食是圓角膠囊、素食友善是方角），
                            不是只有顏色不同——色盲使用者分辨不出兩種綠藍色塊。
                        -->
                        <span
                            v-if="restaurant.venue_badge"
                            class="venue-badge"
                            :data-kind="restaurant.venue_kind ?? undefined"
                        >{{ restaurant.venue_badge }}</span>
                    </span>

                    <span class="card-facts">
                        <span
                            v-if="formatOpenStatus(restaurant)"
                            class="open-status"
                            :data-state="formatOpenStatus(restaurant)?.state"
                        >{{ formatOpenStatus(restaurant)?.text }}</span>
                        <span v-if="formatCuisines(restaurant.cuisines)" class="cuisines">{{ formatCuisines(restaurant.cuisines) }}</span>
                    </span>

                    <span class="card-evidence">
                        <!--
                            「這家店為什麼出現在結果裡」。搜「拉麵」排第一的店如果店名
                            沒有那兩個字，不說明看起來像排序壞了——其實是命中了料理種類。
                        -->
                        <span v-if="formatMatchReasons(restaurant.matched_reasons)" class="match-reason">
                            {{ formatMatchReasons(restaurant.matched_reasons) }}
                        </span>
                        <!--
                            三段標籤而不是裸分數：0–100 的數字看起來像評分，而這個
                            產品刻意不做評分——使用者會把「素食可信度 5」讀成
                            「這家店很爛」，它的實際意思是「還沒有人查證過」。
                        -->
                        <span
                            v-if="restaurant.confidence_level"
                            class="confidence"
                            :data-level="restaurant.confidence_level.code"
                        >{{ restaurant.confidence_level.label }}</span>
                        <span v-if="restaurant.venue_summary" class="venue-summary">{{ restaurant.venue_summary }}</span>
                        <span class="address">{{ formatAddress(restaurant) ?? '地址未提供' }}</span>
                    </span>
                </button>
                <!--
                    放在 button 外面：<a> 巢狀在 <button> 裡是無效 HTML，而且點連結
                    會冒泡觸發卡片的「進詳情」，變成同時開兩個地方。
                -->
                <a
                    class="map-link"
                    :href="googleMapsUrl(restaurant)"
                    target="_blank"
                    rel="noopener noreferrer"
                >在 Google 地圖開啟</a>
            </li>
        </ul>

        <p v-if="invalidFilters" class="notice error" role="alert">
            這組搜尋條件無效（可能是網址被改過）。
            <button type="button" class="inline-clear" @click="clearAll">清除條件</button>
        </p>
        <p v-else-if="loadFailed" class="notice error" role="alert">載入失敗，請再試一次。</p>
        <!--
            空狀態要給得出**可以按的**下一步，不是一段叫使用者自己去試的文字。
            零結果時使用者的問題是「我做錯了什麼」，而後端已經算出「放寬哪一個
            條件會有幾家」——把答案直接放成按鈕。
        -->
        <div v-else-if="!loading && restaurants.length === 0" class="notice empty-state">
            <p>{{ emptyMessage }}</p>

            <!--
                「你是不是要找…」排在放寬條件**之前**：打錯字的時候，錯字才是
                真正的原因，先叫使用者去放寬篩選等於指錯方向。
                點下去才換查詢——不自動改寫，他因此永遠知道看的是哪一個查詢的結果。
            -->
            <p v-if="didYouMean.length" class="did-you-mean">
                你是不是要找
                <button
                    v-for="suggestion in didYouMean"
                    :key="suggestion.term"
                    type="button"
                    class="suggestion"
                    @click="searchVariant(suggestion.term)"
                >{{ suggestion.term }}</button>
                ？
            </p>

            <div v-if="relaxations.length" class="relaxations">
                <p class="relaxations-lead">試試放寬這些條件：</p>
                <button
                    v-for="relaxation in relaxations"
                    :key="relaxation.param"
                    type="button"
                    class="relaxation"
                    @click="applyRelaxation(relaxation)"
                >{{ relaxation.label }}（{{ relaxation.count }} 家）</button>
            </div>

            <!--
                沒有任何可按的下一步時才退回靜態建議——那時候「換個關鍵字」才是
                誠實的建議，而不是一句安慰。已經給了錯字建議就不必再說一次
                「換個關鍵字」，那是同一件事的兩種講法。
            -->
            <p v-else-if="!didYouMean.length && emptySuggestions.length" class="empty-suggestions">
                {{ emptySuggestions.join('，或') }}。
            </p>
        </div>

        <button v-if="nextCursor" type="button" class="more" :disabled="loading" @click="search(false)">
            {{ loading ? '載入中…' : '載入更多' }}
        </button>
    </div>
</template>

<style scoped>
.map-link {
    display: inline-block;
    margin: 0.25rem 0 0.5rem 0.75rem;
    font-size: 0.8125rem;
}

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
    border: 1px solid var(--vm-ink-300);
    border-radius: var(--vm-radius-md);
}

.toolbar button {
    padding: 0.5rem 1rem;
    background: var(--vm-green-600);
    color: var(--vm-white);
    border: none;
    border-radius: var(--vm-radius-md);
    cursor: pointer;
}

.toolbar .clear-keyword {
    background: var(--vm-white);
    color: var(--vm-green-600);
    border: 1px solid var(--vm-ink-300);
}

.global-hint {
    margin: 0.75rem 0 0;
    padding: 0.5rem 0.75rem;
    border-radius: var(--vm-radius-md);
    background: var(--vm-green-50);
    color: var(--vm-green-600);
    font-size: 0.85rem;
}

.scope {
    margin: 0.75rem 0 0.5rem;
    color: var(--vm-ink-500);
    font-size: 0.85rem;
}

ul {
    list-style: none;
    padding: 0;
}

li button {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    width: 100%;
    text-align: left;
    padding: 1rem;
    margin-bottom: 0.5rem;
    border: 1px solid var(--vm-ink-200);
    border-radius: var(--vm-radius-lg);
    background: var(--vm-white);
    cursor: pointer;
}

/* 第一層：身分。店名與徽章同一行，徽章不換行擠掉店名。 */
.card-identity {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.card-identity strong {
    font-size: 1.05rem;
}

/* 第二層：事實。一行講完，用點分隔而不是各佔一行。 */
.card-facts,
.card-evidence {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.35rem 0.5rem;
}

.card-facts > * + *::before,
.card-evidence > * + *::before {
    content: '·';
    margin-right: 0.5rem;
    color: var(--vm-ink-300);
}

/* 第三層：證據。比事實再淡一階，讓視線先落在店名與營業狀態。 */
.card-evidence {
    font-size: 0.85rem;
    color: var(--vm-ink-500);
}

li button:hover {
    border-color: var(--vm-green-600);
}

.address {
    color: var(--vm-ink-700);
    font-size: 0.9rem;
}

.cuisines {
    color: var(--vm-green-600);
    font-size: 0.85rem;
}

/*
 * 純素食店＝圓角膠囊、素食友善＝方角標籤。**形狀也不同，不是只有顏色不同**：
 * 綠與藍對紅綠色盲來說可能是同一個色塊，而「整間店都素」跟「有素食選項」
 * 對素食者是很不一樣的資訊，不能只靠顏色承載。
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

.notice {
    color: var(--vm-ink-500);
    text-align: center;
    padding: 1.5rem 0;
}

.empty-state p {
    margin: 0 0 0.75rem;
}

.relaxations {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
}

.relaxations-lead {
    width: 100%;
    margin: 0 0 0.25rem !important;
    font-size: 0.9rem;
}

.relaxation {
    padding: 0.4rem 0.9rem;
    border: 1px solid var(--vm-green-600);
    border-radius: var(--vm-radius-full);
    background: var(--vm-white);
    color: var(--vm-green-600);
    cursor: pointer;
    font-size: 0.9rem;
}

.relaxation:hover {
    background: var(--vm-green-50);
}

.empty-suggestions {
    font-size: 0.9rem;
}

.did-you-mean {
    font-size: 0.95rem;
    color: var(--vm-ink-700);
}

.did-you-mean .suggestion {
    margin: 0 0.15rem;
    border: none;
    background: none;
    color: var(--vm-green-600);
    font-weight: 600;
    font-size: inherit;
    cursor: pointer;
    text-decoration: underline;
}

.notice.error {
    color: var(--vm-red-600);
}

.more {
    display: block;
    margin: 0 auto;
    padding: 0.5rem 1.25rem;
    border: 1px solid var(--vm-ink-300);
    border-radius: var(--vm-radius-md);
    background: var(--vm-white);
    cursor: pointer;
}

.more:disabled {
    opacity: 0.6;
    cursor: default;
}

.open-status[data-state='open'] {
    color: var(--vm-green-600);
    font-weight: 600;
}

.open-status[data-state='closed'] {
    color: var(--vm-ink-500);
}

/*
 * 「待確認」刻意不上警示色：它是多數店家的現況（實測 1148 家全部落在這一段），
 * 整頁紅字會讓人以為這些店有問題，實際上只是還沒有人去查證。
 */
.confidence[data-level='high'] {
    color: var(--vm-green-700);
    font-weight: 600;
}

.confidence[data-level='verified'] {
    color: var(--vm-blue-700);
}

.sort-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding-top: 0.75rem;
    font-size: 0.9rem;
    color: var(--vm-ink-600);
}

.sort-bar select {
    padding: 0.3rem 0.5rem;
    border: 1px solid var(--vm-ink-300);
    border-radius: var(--vm-radius-md);
    background: var(--vm-white);
    font-size: 0.9rem;
}

.match-reason {
    color: var(--vm-green-600);
    font-size: 0.85rem;
}

.expanded-terms {
    margin: 0.75rem 0 0;
    padding: 0.5rem 0.75rem;
    border-radius: var(--vm-radius-md);
    background: var(--vm-ink-50);
    color: var(--vm-ink-600);
    font-size: 0.85rem;
}

.expanded-terms .variant {
    margin: 0 0.15rem;
    padding: 0.1rem 0.45rem;
    border: 1px solid var(--vm-ink-300);
    border-radius: var(--vm-radius-full);
    background: var(--vm-white);
    color: var(--vm-green-600);
    cursor: pointer;
    font-size: inherit;
}

.expanded-terms .variant:hover {
    border-color: var(--vm-green-600);
}

.expanded-terms .exact-toggle {
    margin-left: 0.5rem;
    border: none;
    background: none;
    color: var(--vm-green-600);
    cursor: pointer;
    text-decoration: underline;
    font-size: inherit;
}

.inline-clear {
    margin-left: 0.5rem;
    border: none;
    background: none;
    color: var(--vm-green-600);
    cursor: pointer;
    text-decoration: underline;
    font-size: inherit;
}
</style>
