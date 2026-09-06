<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import client from '@/api/client';
import { extractApiErrorMessage } from '@/lib/apiError';
import type { ApiSuccess, GeocodedPlace, RestaurantSuggestions, SuggestedRestaurant } from '@/types';

const emit = defineEmits<{
    (e: 'place-selected', place: GeocodedPlace): void;
    (e: 'keyword-search', keyword: string): void;
    (e: 'restaurant-selected', restaurant: SuggestedRestaurant): void;
}>();

const query = ref('');
const results = ref<GeocodedPlace[]>([]);
const suggestions = ref<RestaurantSuggestions>({ restaurants: [], cuisines: [], districts: [] });

/* ---------- 最近搜尋（A7） ---------- */

const RECENT_SEARCHES_KEY = 'veggiemap:recent-searches';
const MAX_RECENT_SEARCHES = 5;

/**
 * 只存字串，不存熱門搜尋——熱門需要 A8 的資料先跑一陣子，而且有隱私與冷啟動問題
 * （見 plan-2026-09-search-ux.md A7）。localStorage 可能被封鎖（私密瀏覽模式、
 * 使用者關閉網站資料），記不住就退回空清單，不影響搜尋本身能不能用。
 */
function loadRecentSearches(): string[] {
    try {
        const raw = localStorage.getItem(RECENT_SEARCHES_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed)
            ? parsed.filter((value): value is string => typeof value === 'string').slice(0, MAX_RECENT_SEARCHES)
            : [];
    } catch {
        return [];
    }
}

const recentSearches = ref<string[]>(loadRecentSearches());

/** 同一個詞再搜一次＝移到最前面，不是變成兩筆。 */
function rememberSearch(term: string) {
    const trimmed = term.trim();

    if (trimmed === '') return;

    const next = [trimmed, ...recentSearches.value.filter((value) => value !== trimmed)].slice(
        0,
        MAX_RECENT_SEARCHES,
    );

    recentSearches.value = next;

    try {
        localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(next));
    } catch {
        // 存不進去就算了——這是方便功能，不是搜尋能不能動的必要條件。
    }
}

function clearRecentSearches() {
    recentSearches.value = [];
    showResults.value = false;

    try {
        localStorage.removeItem(RECENT_SEARCHES_KEY);
    } catch {
        // 同上。
    }
}

/**
 * 已經替哪個字串查過地點。地點查詢只在按下搜尋／Enter 時才發生，而下拉在打字時
 * 就打開了——沒有這個旗標的話，打完字還沒按搜尋的那段時間，清單會顯示
 * 「找不到符合的地點」。**那時候根本還沒查過**（2026-08-26 瀏覽器實測）。
 */
const searchedQuery = ref<string | null>(null);
const loading = ref(false);
const showResults = ref(false);
const error = ref<string | null>(null);

/** 建議清單的觸發門檻。1 個中文字就有意義，但 1 個英文字母沒有。 */
const SUGGEST_MIN_LENGTH = 1;
const SUGGEST_DEBOUNCE_MS = 250;

/**
 * 逐字查詢必須節流：不節流的話「台中一中街」六個字就是六次請求，而且慢的舊回應
 * 可能蓋掉新的。這裡用 debounce ＋ 序號雙保險——debounce 減少請求數，序號保證
 * 只有最後一次的回應會被採用。
 */
let debounceTimer: number | undefined;
let suggestSeq = 0;

const hasSuggestions = computed(
    () => suggestions.value.restaurants.length > 0
        || suggestions.value.cuisines.length > 0
        || suggestions.value.districts.length > 0,
);

function onInput() {
    const q = query.value.trim();

    // 字改了就不再是「查過的那個字串」——不然改完字還會沿用上一次的查詢結果狀態。
    if (q !== searchedQuery.value) {
        searchedQuery.value = null;
    }

    window.clearTimeout(debounceTimer);

    if (q.length < SUGGEST_MIN_LENGTH) {
        suggestions.value = { restaurants: [], cuisines: [], districts: [] };
        // 打字打回空字串＝回到「還沒開始搜尋」的狀態，有最近搜尋就顯示它們，
        // 不是直接關掉清單。
        showResults.value = recentSearches.value.length > 0;

        return;
    }

    showResults.value = true;
    debounceTimer = window.setTimeout(() => void loadSuggestions(q), SUGGEST_DEBOUNCE_MS);
}

async function loadSuggestions(q: string) {
    const seq = ++suggestSeq;

    try {
        const response = await client.get<ApiSuccess<RestaurantSuggestions>>('/restaurants/suggest', {
            // 刻意不帶 city：城市切換器顯示的是「台中」，而 restaurants.city 存的是
            // 「台中市」（還有「臺中市」與大量空字串，見 LookupController::cities 註解）。
            // 拿顯示標籤去比對會把建議全部濾光。API 本身支援 city 參數，給資料乾淨的
            // 使用端用。
            params: { q },
        });

        if (seq !== suggestSeq) return;

        suggestions.value = response.data.data;
    } catch {
        if (seq !== suggestSeq) return;

        // 建議只是輔助，失敗就安靜地不給建議——使用者仍然可以直接按搜尋。
        // 這裡刻意不設 error：跳一個紅字說「建議載入失敗」只會干擾打字。
        suggestions.value = { restaurants: [], cuisines: [], districts: [] };
    }
}

onBeforeUnmount(() => window.clearTimeout(debounceTimer));

async function search() {
    const q = query.value.trim();

    if (q === '') {
        results.value = [];
        searchedQuery.value = null;
        showResults.value = false;
        error.value = null;

        return;
    }

    // 先把候選清單打開：就算 geocode 失敗或太短，使用者仍然看得到「搜尋餐廳」
    // 那一項。舊版在這裡直接 return，打「麵」按 Enter 完全沒有反應。
    showResults.value = true;
    error.value = null;

    if (q.length < 2) {
        results.value = [];
        // 太短不打 Nominatim，但那不等於「查過了、沒有結果」。
        searchedQuery.value = null;

        return;
    }

    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<GeocodedPlace[]>>('/geocode', {
            params: { q },
        });
        results.value = response.data.data;
        searchedQuery.value = q;
    } catch (e: unknown) {
        results.value = [];
        searchedQuery.value = null;
        error.value = extractApiErrorMessage(e, '搜尋地點失敗，請再試一次');
    } finally {
        loading.value = false;
    }
}

function handleBlur() {
    window.setTimeout(() => {
        showResults.value = false;
    }, 150);
}

/**
 * 「拉麵」「滷味」「日式」這類詞在 Nominatim 是查不到地點的，先前打進來只會得到
 * 「找不到符合的地點」——明明後端支援菜色／料理種類搜尋，使用者卻走不到。
 * 所以候選清單永遠先放一個「搜尋餐廳」，地點結果排在它後面。
 */
function searchByKeyword() {
    const keyword = query.value.trim();

    if (keyword === '') {
        return;
    }

    rememberSearch(keyword);
    showResults.value = false;
    emit('keyword-search', keyword);
}

/**
 * 同名的素食店很多，清單上必須看得出差別。OSM 匯入的資料有大量 city／district
 * 是空的（實測台北一批全空），所以退回地址；連地址都沒有就明說「地址未提供」，
 * 而不是留一片空白讓五筆長得一模一樣。
 */
function suggestionHint(restaurant: SuggestedRestaurant): string {
    const locality = [restaurant.city, restaurant.district].filter(Boolean).join(' ');

    return locality || restaurant.address || '地址未提供';
}

function selectRestaurant(restaurant: SuggestedRestaurant) {
    showResults.value = false;
    emit('restaurant-selected', restaurant);
}

/** 選料理種類／行政區＝用那個詞做一次關鍵字搜尋，後端本來就比對這兩種欄位。 */
function selectTerm(term: string) {
    rememberSearch(term);
    query.value = term;
    showResults.value = false;
    emit('keyword-search', term);
}

function select(place: GeocodedPlace) {
    rememberSearch(place.display_name);
    query.value = place.display_name;
    showResults.value = false;
    emit('place-selected', place);
}

/* ---------- 鍵盤操作與 combobox 語意（A6） ---------- */

/**
 * 下拉裡的可選項攤平成一個陣列。清單本身是異質的（搜尋餐廳／店名／料理種類／
 * 行政區／地點），但鍵盤只認得「第幾個」——不攤平的話 ↑↓ 要在四個 v-for 之間
 * 自己算位移，多一種候選就會錯一次。`empty-item` 不進來：它是說明不是選項。
 */
type Option =
    | { kind: 'keyword'; key: string }
    | { kind: 'restaurant'; key: string; restaurant: SuggestedRestaurant }
    | { kind: 'cuisine'; key: string; label: string }
    | { kind: 'district'; key: string; city: string; district: string }
    | { kind: 'place'; key: string; place: GeocodedPlace }
    | { kind: 'recent'; key: string; term: string };

const options = computed<Option[]>(() => {
    // 輸入框空著時清單是「最近搜尋」，不是「搜尋餐廳『』」這種沒有意義的項目——
    // 兩者互斥，同一時間只會出現一種。
    if (query.value.trim() === '') {
        return recentSearches.value.map((term) => ({ kind: 'recent' as const, key: `recent-${term}`, term }));
    }

    const list: Option[] = [{ kind: 'keyword', key: 'keyword' }];

    for (const restaurant of suggestions.value.restaurants) {
        list.push({ kind: 'restaurant', key: `r-${restaurant.id}`, restaurant });
    }

    for (const cuisine of suggestions.value.cuisines) {
        list.push({ kind: 'cuisine', key: `c-${cuisine.code}`, label: cuisine.label });
    }

    for (const district of suggestions.value.districts) {
        list.push({
            kind: 'district',
            key: `d-${district.city}-${district.district}`,
            city: district.city,
            district: district.district,
        });
    }

    for (const place of results.value) {
        list.push({ kind: 'place', key: `p-${place.display_name}`, place });
    }

    return list;
});

/** 同一頁可能有兩個搜尋框（首頁浮動列／列表頁），id 必須每個實例不同。 */
const uid = useId();
const listboxId = `${uid}-listbox`;
const activeIndex = ref(-1);
const listEl = ref<HTMLElement | null>(null);

function optionId(index: number): string {
    return `${uid}-option-${index}`;
}

const activeDescendant = computed(() =>
    showResults.value && activeIndex.value >= 0 ? optionId(activeIndex.value) : undefined,
);

/**
 * 候選內容一變（打字、建議回來、地點回來）就把游標收回去。留在原位的話，
 * 第 3 項本來是「日式料理」，重查之後同一個位置變成別家店，Enter 會選到
 * 使用者沒看過的東西。
 */
watch(options, () => {
    activeIndex.value = -1;
});

watch(showResults, (open) => {
    if (!open) activeIndex.value = -1;
});

function move(delta: number) {
    const count = options.value.length;

    if (count === 0) return;

    // 還沒選任何一項時，↑ 從最後一項開始，↓ 從第一項開始。
    const next = activeIndex.value < 0
        ? (delta > 0 ? 0 : count - 1)
        : (activeIndex.value + delta + count) % count;

    activeIndex.value = next;
    void scrollActiveIntoView();
}

/**
 * 清單有 max-height，游標移出可視範圍時鍵盤使用者會以為沒反應。
 * 用 children[index] 而不是 querySelector：可選項一律排在最前面，
 * 而 useId() 產生的 id（`v-0-option-1`）當 CSS 選擇器要跳脫，多一層踩坑點。
 * `scrollIntoView` 用可選呼叫——jsdom 沒有這個方法，測試環境會直接爆。
 */
async function scrollActiveIntoView() {
    await nextTick();

    const el = listEl.value?.children[activeIndex.value] as HTMLElement | undefined;

    el?.scrollIntoView?.({ block: 'nearest' });
}

function activate(option: Option) {
    switch (option.kind) {
        case 'keyword':
            searchByKeyword();
            break;
        case 'restaurant':
            selectRestaurant(option.restaurant);
            break;
        case 'cuisine':
            selectTerm(option.label);
            break;
        case 'district':
            selectTerm(option.district);
            break;
        case 'place':
            select(option.place);
            break;
        case 'recent':
            selectTerm(option.term);
            break;
    }
}

function onArrow(event: KeyboardEvent, delta: number) {
    // 下拉關著時先把它打開：輸入框有字，或者空著但有最近搜尋可以顯示。
    if (!showResults.value) {
        if (query.value.trim() === '' && recentSearches.value.length === 0) return;

        showResults.value = true;
        activeIndex.value = -1;
    }

    event.preventDefault();
    move(delta);
}

/**
 * Enter 有兩種意思：游標停在某個候選上＝選它；沒停在任何候選上＝維持舊行為，
 * 送出地點查詢。舊的 @keyup.enter 換成 keydown 才擋得住表單預設行為。
 */
function onEnter(event: KeyboardEvent) {
    if (showResults.value && activeIndex.value >= 0) {
        event.preventDefault();
        activate(options.value[activeIndex.value]);

        return;
    }

    void search();
}

/**
 * Esc 關掉清單，但**不能**讓輸入框被清空：Chrome 對 `<input type="search">` 的
 * 原生行為就是按 Esc 清字，2026-09-06 在真瀏覽器實測到（jsdom 沒有這個行為，
 * 單元測試看不出來）。清單開著時擋掉預設行為，等於「第一次 Esc 收清單、
 * 第二次 Esc 才清字」——跟一般 combobox 的習慣一致。
 */
function onEscape(event: KeyboardEvent) {
    if (!showResults.value) return;

    event.preventDefault();
    showResults.value = false;
}

/** Tab 離開＝關掉清單但保留使用者打的字，不要幫他選。 */
function onTab() {
    showResults.value = false;
}
</script>

<template>
    <div class="search-box">
        <input
            v-model="query"
            type="search"
            role="combobox"
            aria-autocomplete="list"
            aria-haspopup="listbox"
            :aria-expanded="showResults"
            :aria-controls="listboxId"
            :aria-activedescendant="activeDescendant"
            placeholder="搜尋地點或餐廳，例如「台中一中街」「拉麵」"
            @input="onInput"
            @keydown.down="onArrow($event, 1)"
            @keydown.up="onArrow($event, -1)"
            @keydown.enter="onEnter"
            @keydown.esc="onEscape"
            @keydown.tab="onTab"
            @focus="showResults = query.trim().length > 0 || recentSearches.length > 0"
            @blur="handleBlur"
        />
        <!--
            @mousedown.prevent 跟下面每個候選項用的是同一招，理由也一樣：不加的話
            點按鈕會先讓 input 失焦，handleBlur 在 150ms 後把清單關掉，而 geocode
            要打外部 Nominatim、回來時清單早就不在了——按鈕看起來完全沒反應
            （用 Enter 反而正常，因為鍵盤不會觸發 blur）。2026-08-26 在真瀏覽器實測到。
        -->
        <button type="button" :disabled="loading" @mousedown.prevent @click="search">
            {{ loading ? '搜尋中…' : '搜尋' }}
        </button>

        <ul v-if="showResults" :id="listboxId" ref="listEl" class="results" role="listbox" aria-label="搜尋建議">
            <!--
              「最近搜尋」的標題列不是選項（沒有 role="option"，鍵盤游標不會停在
              它上面），跟下面 empty-item 是同一個做法。清除按鈕也要
              @mousedown.prevent，理由跟其他候選項一樣：不擋的話點下去會先讓
              input 失焦，清單在按鈕的 click 事件觸發前就被 handleBlur 關掉了。
            -->
            <li v-if="query.trim() === '' && options.length" class="recent-header" role="presentation">
                最近搜尋
                <button type="button" class="clear-recent" @mousedown.prevent="clearRecentSearches">清除</button>
            </li>
            <li
                v-for="(option, index) in options"
                :id="optionId(index)"
                :key="option.key"
                role="option"
                :aria-selected="index === activeIndex"
                :class="[
                    option.kind === 'keyword' ? 'keyword-option' : '',
                    option.kind === 'restaurant'
                        || option.kind === 'cuisine'
                        || option.kind === 'district'
                        || option.kind === 'recent'
                        ? 'suggestion'
                        : '',
                    { active: index === activeIndex },
                ]"
                @mousedown.prevent="activate(option)"
                @mousemove="activeIndex = index"
            >
                <template v-if="option.kind === 'keyword'">
                    搜尋餐廳「{{ query.trim() }}」（店名、菜色、料理種類）
                </template>
                <template v-else-if="option.kind === 'restaurant'">
                    {{ option.restaurant.name }}
                    <span class="hint">{{ suggestionHint(option.restaurant) }}</span>
                </template>
                <template v-else-if="option.kind === 'cuisine'">
                    {{ option.label }}<span class="hint">料理種類</span>
                </template>
                <template v-else-if="option.kind === 'district'">
                    {{ option.city }} {{ option.district }}<span class="hint">地區</span>
                </template>
                <template v-else-if="option.kind === 'place'">
                    {{ option.place.display_name }}
                </template>
                <template v-else>
                    {{ option.term }}
                </template>
            </li>
            <!--
              只有真的查過地點才說「找不到」。打字時下拉就開了，但地點查詢要按下
              搜尋才會發生——沒有這個條件的話，那段空窗期會顯示一個還沒發生的結論。
              這一項不是選項（不進 options、沒有 role="option"），鍵盤游標不會停在它上面。
            -->
            <li
                v-if="!loading && searchedQuery === query.trim() && results.length === 0 && !hasSuggestions"
                class="empty-item"
            >
                找不到符合的地點
            </li>
        </ul>
        <p v-if="error" class="empty" role="alert">{{ error }}</p>
    </div>
</template>

<style scoped>
.search-box {
    position: relative;
    display: flex;
    gap: 0.5rem;
}

input {
    flex: 1;
    padding: 0.5rem 0.75rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    font-size: 1rem;
}

button {
    padding: 0.5rem 1rem;
    background: #2f855a;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

button:disabled {
    opacity: 0.6;
    cursor: default;
}

.results,
.empty {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    margin-top: 0.25rem;
    z-index: 1000;
    max-height: 240px;
    overflow-y: auto;
    list-style: none;
    padding: 0.25rem 0;
}

.results li {
    padding: 0.5rem 0.75rem;
    cursor: pointer;
}

.results li:hover,
.results li.active {
    background: #f0fff4;
}

.results .recent-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.35rem 0.75rem;
    color: #718096;
    font-size: 0.8rem;
    cursor: default;
}

.results .clear-recent {
    padding: 0;
    border: none;
    background: none;
    color: #2f855a;
    font-size: 0.8rem;
    cursor: pointer;
    text-decoration: underline;
}

.results .keyword-option {
    font-weight: 600;
    color: #2f855a;
    border-bottom: 1px solid #edf2f7;
}

/* 不是選項，只是說明，所以不給 hover 也不給游標。 */
.results .hint {
    margin-left: 0.5rem;
    color: #718096;
    font-size: 0.8rem;
}

.results .empty-item {
    color: #718096;
    cursor: default;
}

.results .empty-item:hover {
    background: none;
}

.empty {
    padding: 0.5rem 0.75rem;
    color: #718096;
}
</style>
