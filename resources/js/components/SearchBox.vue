<script setup lang="ts">
import { ref } from 'vue';
import client from '@/api/client';
import type { ApiSuccess, GeocodedPlace } from '@/types';

const emit = defineEmits<{
    (e: 'place-selected', place: GeocodedPlace): void;
}>();

const query = ref('');
const results = ref<GeocodedPlace[]>([]);
const loading = ref(false);
const showResults = ref(false);

async function search() {
    if (query.value.trim().length < 2) {
        results.value = [];
        showResults.value = false;
        return;
    }

    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<GeocodedPlace[]>>('/geocode', {
            params: { q: query.value.trim() },
        });
        results.value = response.data.data;
        showResults.value = true;
    } finally {
        loading.value = false;
    }
}

function handleBlur() {
    window.setTimeout(() => {
        showResults.value = false;
    }, 150);
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
            placeholder="搜尋地點，例如「台中一中街」"
            @keyup.enter="search"
            @blur="handleBlur"
        />
        <button type="button" :disabled="loading" @click="search">{{ loading ? '搜尋中…' : '搜尋' }}</button>

        <ul v-if="showResults && results.length" class="results">
            <li v-for="place in results" :key="place.display_name" @mousedown.prevent="select(place)">
                {{ place.display_name }}
            </li>
        </ul>
        <p v-else-if="showResults && !loading" class="empty">找不到符合的地點</p>
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

.empty {
    padding: 0.5rem 0.75rem;
    color: #718096;
}
</style>
