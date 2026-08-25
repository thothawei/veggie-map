<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
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

    window.clearTimeout(debounceTimer);

    if (q.length < SUGGEST_MIN_LENGTH) {
        suggestions.value = { restaurants: [], cuisines: [], districts: [] };
        showResults.value = false;

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

        return;
    }

    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<GeocodedPlace[]>>('/geocode', {
            params: { q },
        });
        results.value = response.data.data;
    } catch (e: unknown) {
        results.value = [];
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
    query.value = term;
    showResults.value = false;
    emit('keyword-search', term);
}

function select(place: GeocodedPlace) {
    query.value = place.display_name;
    showResults.value = false;
    emit('place-selected', place);
}
</script>

<template>
    <div class="search-box">
        <input
            v-model="query"
            type="search"
            placeholder="搜尋地點或餐廳，例如「台中一中街」「拉麵」"
            @input="onInput"
            @keyup.enter="search"
            @focus="showResults = query.trim().length > 0"
            @blur="handleBlur"
        />
        <button type="button" :disabled="loading" @click="search">{{ loading ? '搜尋中…' : '搜尋' }}</button>

        <ul v-if="showResults" class="results">
            <li class="keyword-option" @mousedown.prevent="searchByKeyword">
                搜尋餐廳「{{ query.trim() }}」（店名、菜色、料理種類）
            </li>
            <li
                v-for="restaurant in suggestions.restaurants"
                :key="`r-${restaurant.id}`"
                class="suggestion"
                @mousedown.prevent="selectRestaurant(restaurant)"
            >
                {{ restaurant.name }}
                <span class="hint">{{ suggestionHint(restaurant) }}</span>
            </li>

            <li
                v-for="cuisine in suggestions.cuisines"
                :key="`c-${cuisine.code}`"
                class="suggestion"
                @mousedown.prevent="selectTerm(cuisine.label)"
            >
                {{ cuisine.label }}<span class="hint">料理種類</span>
            </li>

            <li
                v-for="district in suggestions.districts"
                :key="`d-${district.city}-${district.district}`"
                class="suggestion"
                @mousedown.prevent="selectTerm(district.district)"
            >
                {{ district.city }} {{ district.district }}<span class="hint">地區</span>
            </li>

            <li v-for="place in results" :key="place.display_name" @mousedown.prevent="select(place)">
                {{ place.display_name }}
            </li>
            <li v-if="!loading && results.length === 0 && !hasSuggestions" class="empty-item">
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

.results li:hover {
    background: #f0fff4;
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
