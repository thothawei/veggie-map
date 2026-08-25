<script setup lang="ts">
import { ref } from 'vue';
import client from '@/api/client';
import { extractApiErrorMessage } from '@/lib/apiError';
import type { ApiSuccess, GeocodedPlace } from '@/types';

const emit = defineEmits<{
    (e: 'place-selected', place: GeocodedPlace): void;
    (e: 'keyword-search', keyword: string): void;
}>();

const query = ref('');
const results = ref<GeocodedPlace[]>([]);
const loading = ref(false);
const showResults = ref(false);
const error = ref<string | null>(null);

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
            @keyup.enter="search"
            @focus="showResults = query.trim().length > 0"
            @blur="handleBlur"
        />
        <button type="button" :disabled="loading" @click="search">{{ loading ? '搜尋中…' : '搜尋' }}</button>

        <ul v-if="showResults" class="results">
            <li class="keyword-option" @mousedown.prevent="searchByKeyword">
                搜尋餐廳「{{ query.trim() }}」（店名、菜色、料理種類）
            </li>
            <li v-for="place in results" :key="place.display_name" @mousedown.prevent="select(place)">
                {{ place.display_name }}
            </li>
            <li v-if="!loading && results.length === 0" class="empty-item">找不到符合的地點</li>
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
