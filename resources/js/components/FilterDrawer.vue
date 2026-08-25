<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import client from '@/api/client';
import { FEATURE_CODES, type FeatureCode } from '@/lib/features';
import type { ApiSuccess, DietType, Feature, RestaurantSearchParams } from '@/types';

const filters = defineModel<Partial<RestaurantSearchParams>>('filters', { required: true });

const diets = ref<DietType[]>([]);
const features = ref<Feature[]>([]);

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

const activeCount = computed(
    () => Object.values(filters.value).filter((value) => value !== undefined && value !== null).length,
);

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

function toggleDiet(code: string) {
    replaceFilters((next) => {
        if (next.diet === code) {
            delete next.diet;

            return;
        }

        next.diet = code;
    });
}

function isFeatureCode(code: string): code is FeatureCode {
    return (FEATURE_CODES as readonly string[]).includes(code);
}

function isFeatureOn(code: string): boolean {
    return isFeatureCode(code) && Boolean(filters.value[code]);
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
        <div class="drawer-bar">
            <button
                type="button"
                class="toggle"
                :aria-expanded="open"
                aria-controls="filter-panel"
                @click="userOpen = !open"
            >
                篩選
                <span v-if="activeCount" class="count">{{ activeCount }}</span>
                <span class="caret" :class="{ up: open }" aria-hidden="true">▾</span>
            </button>

            <button v-if="activeCount" type="button" class="clear" @click="clearAll">清除</button>
        </div>

        <div v-show="open" id="filter-panel" class="panel">
            <div class="group">
                <span class="label">飲食類型</span>
                <button
                    v-for="diet in diets"
                    :key="diet.code"
                    type="button"
                    class="chip"
                    :class="{ active: filters.diet === diet.code }"
                    :aria-pressed="filters.diet === diet.code"
                    @click="toggleDiet(diet.code)"
                >
                    {{ diet.label }}
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
        </div>
    </div>
</template>

<style scoped>
.filter-drawer {
    padding: 0.75rem 0 0;
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
    border-radius: 999px;
    border: 1px solid #cbd5e0;
    background: #fff;
    color: #1f2933;
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
    border-radius: 999px;
    background: #2f855a;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 600;
}

.caret {
    font-size: 0.7rem;
    color: #718096;
    transition: transform 0.15s ease;
}

.caret.up {
    transform: rotate(180deg);
}

.clear {
    padding: 0.35rem 0.75rem;
    border: none;
    background: none;
    color: #2f855a;
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: underline;
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
    color: #718096;
    margin-right: 0.25rem;
}

.chip {
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    border: 1px solid #cbd5e0;
    background: #fff;
    cursor: pointer;
    font-size: 0.85rem;
}

.chip:hover {
    border-color: #2f855a;
    color: #2f855a;
}

.chip.active {
    background: #2f855a;
    border-color: #2f855a;
    color: #fff;
}

.chip.active:hover {
    color: #fff;
}

.toggle:focus-visible,
.clear:focus-visible,
.chip:focus-visible {
    outline: 2px solid #2f855a;
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .caret {
        transition: none;
    }
}
</style>
