<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useFavoritesStore } from '@/stores/favorites';
import type { Restaurant } from '@/types';

const router = useRouter();
const favorites = useFavoritesStore();

function goToDetail(restaurant: Restaurant) {
    // slug 優先：網址看得懂是規劃第二十六節的目的。沒有 slug（建議清單只回四個
    // 欄位）就退回 id，後端兩種都收。
    router.push({ name: 'restaurant-detail', params: { id: restaurant.slug ?? restaurant.id } });
}

async function remove(restaurant: Restaurant) {
    await favorites.remove(restaurant.id);
}

onMounted(() => favorites.fetchAll());
</script>

<template>
    <div class="favorites">
        <h1>我的收藏</h1>
        <p v-if="favorites.loaded && favorites.restaurants.length === 0">還沒有收藏任何餐廳。</p>
        <ul>
            <li v-for="restaurant in favorites.restaurants" :key="restaurant.id">
                <button type="button" class="name" @click="goToDetail(restaurant)">
                    {{ restaurant.name }}
                </button>
                <button type="button" class="remove" @click="remove(restaurant)">移除</button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.favorites {
    max-width: 600px;
    margin: 0 auto;
    padding: 1.5rem;
}

ul {
    list-style: none;
    padding: 0;
}

li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 0.5rem;
}

.name {
    background: none;
    border: none;
    font-size: 1rem;
    cursor: pointer;
    text-align: left;
}

.remove {
    background: #fff5f5;
    color: #c53030;
    border: 1px solid #feb2b2;
    border-radius: 6px;
    padding: 0.3rem 0.6rem;
    cursor: pointer;
}
</style>
