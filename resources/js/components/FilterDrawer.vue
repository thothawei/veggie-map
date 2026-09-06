<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import client from '@/api/client';
import { FEATURE_CODES, type FeatureCode } from '@/lib/features';
import { PRICE_LEVELS } from '@/composables/useFilterQuery';
import { applyMenuItemDiets, applyVenueScopeMeta, venueScopeDefault, venueScopeMeta } from '@/lib/dietCatalog';
import type {
    ApiSuccess,
    ConfidenceFilter,
    DietType,
    Feature,
    MenuItemDiet,
    RestaurantFacets,
    RestaurantSearchParams,
    VenueScopeMeta,
} from '@/types';

const filters = defineModel<Partial<RestaurantSearchParams>>('filters', { required: true });

const props = defineProps<{
    /**
     * 目前條件下有幾家店（B3：「顯示 N 家結果」）。沒有 `facets` 時這是「現在
     * 已經查到的筆數」；有 `facets` 時（B4）優先顯示三個候選值加總——兩者
     * 通常一樣，只有在使用者已經選了某個 quick chip 時才會不同（那時候
     * `resultCount` 是「選了這個之後」，`facets` 說的是「換成別的候選會怎樣」，
     * 各自負責不同的問題，不需要對齊）。
     */
    resultCount?: number;
    /** 目前這批是不是還有更多（cursor 分頁的下一頁），顯示成「100+ 家」。 */
    hasMoreResults?: boolean;
    /**
     * `GET /restaurants/facets` 的結果（B4）：常駐 quick filter 每個候選值
     * 按下去會剩幾家。沒有帶這個 prop（或還在載入中）時 quick chip 不顯示
     * 數字——不要顯示 0 或空字串假裝是答案。
     */
    facets?: RestaurantFacets | null;
}>();

function scopeFacetCount(value: string): number | undefined {
    return props.facets?.venue_scope?.find((option) => option.value === value)?.count;
}

// `?.` 一路到底而不是只擋 `facets` 本身——後端／測試 mock 任何一層漏了欄位
// 都只會讓數字不顯示，不會讓整個 FilterDrawer 崩潰（2026-09-06 實測踩到：
// 舊測試的 catch-all mock 回 `{ data: [] }`，`facets.value` 變成陣列，
// `.open_now.count` 直接炸掉整個元件）。
const openNowFacetCount = computed(() => props.facets?.open_now?.count);
const confidenceFacetCount = computed(() => props.facets?.confidence_min?.count);

const diets = ref<DietType[]>([]);
const features = ref<Feature[]>([]);
const scopeMeta = ref(venueScopeMeta());
/** 門檻與標籤都來自後端 config/vegetarian.php，不在這裡決定「幾分算有查證」。 */
const confidenceFilters = ref<ConfidenceFilter[]>([]);

const WIDE_SCREEN = '(min-width: 768px)';

/**
 * 手機上這排晶片會佔掉三行，把地圖整個擠到摺線以下（375×812 實測 hero 佔了約 570px），
 * 所以窄螢幕預設收起來，桌機空間夠就直接展開。
 *
 * 這裡刻意用「持續監聽」而不是掛載時讀一次：實測過在隱藏的瀏覽器分頁裡掛載時
 * `window.innerWidth` 是 0、matchMedia 一律回 false，一次性判斷會把桌機也誤判成窄螢幕、
 * 而且之後永遠不會修正。監聽版本等版面確定後會自己補正。
 */
const isWideScreen = ref(false);
let mediaQuery: MediaQueryList | null = null;

function syncWideScreen(event: MediaQueryListEvent | MediaQueryList) {
    isWideScreen.value = event.matches;
}

/** null＝使用者還沒表態，跟著螢幕寬度走；一旦手動開關就以他的選擇為準。 */
const userOpen = ref<boolean | null>(null);

const open = computed(() => userOpen.value ?? isWideScreen.value);

const currentScope = computed(() => filters.value.venue_scope ?? scopeMeta.value.default);

const dietGroups = computed(() => {
    const hasLabels = diets.value.some((diet) => diet.group_label);
    if (!hasLabels) {
        return [{ label: '飲食類型', items: diets.value }];
    }

    const groups = new Map<string, DietType[]>();
    for (const diet of diets.value) {
        const label = diet.group_label || diet.kind || '';
        const items = groups.get(label) ?? [];
        items.push(diet);
        groups.set(label, items);
    }

    return [...groups.entries()].map(([label, items]) => ({ label, items }));
});

/**
 * 常駐 quick filter（B3）：營業中／純素食店↔含友善店／高可信度。這三個是
 * 「最常用」的暫定選擇，不是量出來的——A8 的零結果紀錄才剛上線，還沒有
 * 一週以上的資料可以看真實使用率。等資料夠了再回頭調整這三個是誰。
 *
 * 可信度只挑**最高**那一級當 quick chip（`confidenceFilters` 由後端依分數
 * 由低到高排序，見 config/vegetarian.php）——「高度可信」是使用者最想
 * 一鍵套用的門檻，較低的門檻（例如「有查證」）留在「更多篩選」裡。
 */
const quickConfidence = computed(() => confidenceFilters.value[confidenceFilters.value.length - 1] ?? null);
const morePanelConfidenceFilters = computed(() => confidenceFilters.value.slice(0, -1));

const activeCount = computed(() => {
    let count = 0;

    for (const [key, value] of Object.entries(filters.value)) {
        if (value === undefined || value === null) {
            continue;
        }

        if (key === 'venue_scope' && value === scopeMeta.value.default) {
            continue;
        }

        count += 1;
    }

    return count;
});

onMounted(async () => {
    mediaQuery = window.matchMedia(WIDE_SCREEN);
    syncWideScreen(mediaQuery);
    mediaQuery.addEventListener('change', syncWideScreen);

    const [dietsRes, featuresRes] = await Promise.all([
        client.get<ApiSuccess<DietType[]>>('/diets'),
        client.get<ApiSuccess<Feature[]>>('/features'),
    ]);
    diets.value = dietsRes.data.data;
    features.value = featuresRes.data.data;

    const meta = dietsRes.data.meta?.venue_scope as VenueScopeMeta | undefined;
    applyVenueScopeMeta(meta);
    applyMenuItemDiets(dietsRes.data.meta?.menu_item_diets as MenuItemDiet[] | undefined);
    confidenceFilters.value = (dietsRes.data.meta?.confidence_filters as ConfidenceFilter[] | undefined) ?? [];
    scopeMeta.value = venueScopeMeta();
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', syncWideScreen);
});

/**
 * 一律整組替換而不是就地改欄位。就地改的話，當父層把 filters 接到網址（computed 的
 * getter 每次回傳新物件）時，改動會落在一個暫時物件上、永遠傳不出去。整組替換走的是
 * defineModel 的 emit，父層要存在 ref 還是網址都行。
 */
function replaceFilters(mutate: (next: Partial<RestaurantSearchParams>) => void) {
    const next = { ...filters.value };
    mutate(next);
    filters.value = next;
}

function selectScope(value: string) {
    replaceFilters((next) => {
        if (value === venueScopeDefault()) {
            delete next.venue_scope;

            return;
        }

        next.venue_scope = value;
    });
}

/**
 * 飲食類型是**多選**：素食者常常「全素或蛋奶素都可以」，逼他們一次只能挑一個，
 * 就得分兩次搜尋再自己合併。後端多個 code 之間是 OR。
 */
function selectedDiets(): string[] {
    const raw = filters.value.diet;

    return typeof raw === 'string' && raw !== '' ? raw.split(',') : [];
}

function isDietOn(code: string): boolean {
    return selectedDiets().includes(code);
}

function toggleDiet(code: string) {
    replaceFilters((next) => {
        const current = selectedDiets();
        const updated = current.includes(code)
            ? current.filter((value) => value !== code)
            : [...current, code];

        if (updated.length === 0) {
            delete next.diet;

            return;
        }

        next.diet = updated.join(',');
    });
}

function isFeatureCode(code: string): code is FeatureCode {
    return (FEATURE_CODES as readonly string[]).includes(code);
}

function isFeatureOn(code: string): boolean {
    return isFeatureCode(code) && Boolean(filters.value[code]);
}

/** 再點一次同一個價位＝取消，跟飲食類型的晶片行為一致（單選，不是多選）。 */
function togglePriceLevel(level: number) {
    replaceFilters((next) => {
        if (next.price_level === level) {
            delete next.price_level;

            return;
        }

        next.price_level = level;
    });
}

/** 再點一次同一個門檻＝取消，跟價位晶片一致（單選）。 */
function toggleConfidence(value: number) {
    replaceFilters((next) => {
        if (next.confidence_min === value) {
            delete next.confidence_min;

            return;
        }

        next.confidence_min = value;
    });
}

/** 「營業中」不是店家屬性而是此刻的狀態，所以自成一組，不混在特色晶片裡。 */
function toggleOpenNow() {
    replaceFilters((next) => {
        if (next.open_now) {
            delete next.open_now;

            return;
        }

        next.open_now = true;
    });
}

function toggleFeature(code: string) {
    if (!isFeatureCode(code)) {
        return;
    }

    replaceFilters((next) => {
        if (next[code]) {
            delete next[code];

            return;
        }

        next[code] = true;
    });
}

// 一個一個點回去才能取消太麻煩，而且使用者未必記得剛剛點了哪些。
function clearAll() {
    filters.value = {};
}
</script>

<template>
    <div class="filter-drawer">
        <!--
          常駐 quick filter（B3）：不用打開「更多篩選」就按得到的三個最常用
          條件。哪三個是暫定的，見上面 quickConfidence 的註解。
        -->
        <div class="quick-chips">
            <div v-if="scopeMeta.values.length" class="group">
                <span class="label">{{ scopeMeta.group_label }}</span>
                <button
                    v-for="option in scopeMeta.values"
                    :key="option.value"
                    type="button"
                    class="chip"
                    :class="{ active: currentScope === option.value, 'zero-count': scopeFacetCount(option.value) === 0 }"
                    :aria-pressed="currentScope === option.value"
                    @click="selectScope(option.value)"
                >
                    {{ option.label }}
                    <span v-if="scopeFacetCount(option.value) !== undefined" class="facet-count">
                        （{{ scopeFacetCount(option.value) }}）
                    </span>
                </button>
            </div>

            <div class="group">
                <span class="label">時間</span>
                <button
                    type="button"
                    class="chip"
                    :class="{ active: Boolean(filters.open_now), 'zero-count': openNowFacetCount === 0 }"
                    :aria-pressed="Boolean(filters.open_now)"
                    @click="toggleOpenNow"
                >
                    營業中
                    <span v-if="openNowFacetCount !== undefined" class="facet-count">（{{ openNowFacetCount }}）</span>
                </button>
            </div>

            <div v-if="quickConfidence" class="group">
                <button
                    type="button"
                    class="chip"
                    :class="{ active: filters.confidence_min === quickConfidence.value, 'zero-count': confidenceFacetCount === 0 }"
                    :aria-pressed="filters.confidence_min === quickConfidence.value"
                    @click="toggleConfidence(quickConfidence.value)"
                >
                    {{ quickConfidence.label }}
                    <span v-if="confidenceFacetCount !== undefined" class="facet-count">（{{ confidenceFacetCount }}）</span>
                </button>
            </div>
        </div>

        <div class="drawer-bar">
            <button
                type="button"
                class="toggle"
                :aria-expanded="open"
                aria-controls="filter-panel"
                @click="userOpen = !open"
            >
                更多篩選
                <span v-if="activeCount" class="count">{{ activeCount }}</span>
                <span class="caret" :class="{ up: open }" aria-hidden="true">▾</span>
            </button>
        </div>

        <div v-show="open" id="filter-panel" class="panel">
            <div v-for="group in dietGroups" :key="group.label" class="group">
                <span class="label">{{ group.label }}</span>
                <button
                    v-for="diet in group.items"
                    :key="diet.code"
                    type="button"
                    class="chip"
                    :class="{ active: isDietOn(diet.code) }"
                    :aria-pressed="isDietOn(diet.code)"
                    @click="toggleDiet(diet.code)"
                >
                    {{ diet.label }}
                </button>
            </div>

            <div class="group">
                <span class="label">價位</span>
                <button
                    v-for="level in PRICE_LEVELS"
                    :key="level"
                    type="button"
                    class="chip"
                    :class="{ active: filters.price_level === level }"
                    :aria-pressed="filters.price_level === level"
                    :aria-label="`價位 ${level} 級`"
                    @click="togglePriceLevel(level)"
                >
                    {{ '$'.repeat(level) }}
                </button>
            </div>

            <div v-if="morePanelConfidenceFilters.length" class="group">
                <span class="label">素食可信度</span>
                <button
                    v-for="option in morePanelConfidenceFilters"
                    :key="option.value"
                    type="button"
                    class="chip"
                    :class="{ active: filters.confidence_min === option.value }"
                    :aria-pressed="filters.confidence_min === option.value"
                    @click="toggleConfidence(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>

            <div class="group">
                <span class="label">特色</span>
                <button
                    v-for="feature in features"
                    :key="feature.code"
                    type="button"
                    class="chip"
                    :class="{ active: isFeatureOn(feature.code) }"
                    :aria-pressed="isFeatureOn(feature.code)"
                    @click="toggleFeature(feature.code)"
                >
                    {{ feature.label }}
                </button>
            </div>

            <!--
              底部固定「清除全部」與「顯示 N 家結果」（B3）。後者目前用現有已
              查到的筆數，不是「按下某個篩選之後會剩幾家」的預測——那需要
              B4 的 /restaurants/facets，這一輪還沒做。
            -->
            <div class="panel-footer">
                <button v-if="activeCount" type="button" class="clear" @click="clearAll">清除全部</button>
                <button
                    v-if="resultCount !== undefined"
                    type="button"
                    class="show-results"
                    @click="userOpen = false"
                >
                    顯示 {{ resultCount }}{{ hasMoreResults ? '+' : '' }} 家結果
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.filter-drawer {
    padding: 0.75rem 0 0;
}

.quick-chips {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.quick-chips .group {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
    justify-content: center;
}

.drawer-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.85rem;
    border-radius: var(--vm-radius-full);
    border: 1px solid var(--vm-ink-300);
    background: var(--vm-white);
    color: var(--vm-ink-800);
    cursor: pointer;
    font-size: 0.9rem;
}

.count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 0.3rem;
    border-radius: var(--vm-radius-full);
    background: var(--vm-green-600);
    color: var(--vm-white);
    font-size: 0.72rem;
    font-weight: 600;
}

.caret {
    font-size: 0.7rem;
    color: var(--vm-ink-500);
    transition: transform 0.15s ease;
}

.caret.up {
    transform: rotate(180deg);
}

.clear {
    padding: 0.35rem 0.75rem;
    border: none;
    background: none;
    color: var(--vm-green-600);
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: underline;
}

.panel-footer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    flex-basis: 100%;
    padding-top: 0.5rem;
    border-top: 1px solid var(--vm-ink-100);
}

.show-results {
    padding: 0.5rem 1.25rem;
    border: none;
    border-radius: var(--vm-radius-full);
    background: var(--vm-green-600);
    color: var(--vm-white);
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
}

.panel {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.75rem 1rem;
    padding-top: 0.75rem;
}

.group {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
    justify-content: center;
}

.label {
    font-size: 0.85rem;
    color: var(--vm-ink-500);
    margin-right: 0.25rem;
}

.chip {
    padding: 0.35rem 0.75rem;
    border-radius: var(--vm-radius-full);
    border: 1px solid var(--vm-ink-300);
    background: var(--vm-white);
    cursor: pointer;
    font-size: 0.85rem;
}

.chip:hover {
    border-color: var(--vm-green-600);
    color: var(--vm-green-600);
}

.chip.active {
    background: var(--vm-green-600);
    border-color: var(--vm-green-600);
    color: var(--vm-white);
}

/* 「按下去會剩幾家」（B4）。文字放在 chip 裡面，不是另外一個徽章——
   跟晶片本身的按下去動作是同一件事，不需要分開強調。 */
.facet-count {
    opacity: 0.75;
    font-size: 0.8em;
}

/* 0 家的 chip 變灰但不隱藏——隱藏會讓使用者以為這個選項消失了。 */
.chip.zero-count {
    opacity: 0.55;
}

.chip.active:hover {
    color: var(--vm-white);
}

.toggle:focus-visible,
.clear:focus-visible,
.show-results:focus-visible,
.chip:focus-visible {
    outline: 2px solid var(--vm-green-600);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .caret {
        transition: none;
    }
}
</style>
