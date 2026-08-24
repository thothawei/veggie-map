<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import client from '@/api/client';
import FilterDrawer from '@/components/FilterDrawer.vue';
import type { ApiSuccess, Restaurant, RestaurantSearchParams } from '@/types';

const router = useRouter();
const restaurants = ref<Restaurant[]>([]);
const keyword = ref('');
const filters = ref<Partial<RestaurantSearchParams>>({});
const nextCursor = ref<string | null>(null);
const loading = ref(false);

async function search(reset = true) {
    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
            params: {
                keyword: keyword.value || undefined,
                sort: 'newest',
                per_page: 20,
                cursor: reset ? undefined : (nextCursor.value ?? undefined),
                ...filters.value,
            },
        });
        restaurants.value = reset ? response.data.data : [...restaurants.value, ...response.data.data];
        nextCursor.value = (response.data.meta?.next_cursor as string | null) ?? null;
    } finally {
        loading.value = false;
    }
}

function goToDetail(restaurant: Restaurant) {
    router.push({ name: 'restaurant-detail', params: { id: restaurant.id } });
}

watch(filters, () => search(true), { deep: true });
search(true);
</script>

<template>
    <div class="restaurant-list">
        <div class="toolbar">
            <input v-model="keyword" type="search" placeholder="搜尋餐廳名稱" @keyup.enter="search(true)" />
            <button type="button" @click="search(true)">搜尋</button>
        </div>
        <FilterDrawer v-model:filters="filters" />

        <ul>
            <li v-for="restaurant in restaurants" :key="restaurant.id">
                <button type="button" @click="goToDetail(restaurant)">
                    <strong>{{ restaurant.name }}</strong>
                    <span>⭐ {{ restaurant.rating.toFixed(1) }} ({{ restaurant.rating_count }})</span>
                    <span class="address">{{ restaurant.address }}</span>
                </button>
            </li>
        </ul>

        <p v-if="!loading && restaurants.length === 0">沒有符合條件的餐廳</p>
        <button v-if="nextCursor" type="button" :disabled="loading" @click="search(false)">
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

.address {
    color: #718096;
    font-size: 0.85rem;
}
</style>
