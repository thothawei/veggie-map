<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import client from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import { useFavoritesStore } from '@/stores/favorites';
import { extractApiErrorMessage } from '@/lib/apiError';
import { safeHttpUrl } from '@/lib/redirect';
import type { ApiSuccess, DietType, Feature, Restaurant } from '@/types';

const props = defineProps<{ id: string }>();

const auth = useAuthStore();
const favorites = useFavoritesStore();

const restaurant = ref<Restaurant | null>(null);
const loading = ref(true);
const notFound = ref(false);
const dietLabels = ref<Record<string, string>>({});
const featureLabels = ref<Record<string, string>>({});

const reviewRating = ref(5);
const reviewComment = ref('');
const submittingReview = ref(false);
const reviewError = ref<string | null>(null);

const isFavorite = computed(() => (restaurant.value ? favorites.isFavorite(restaurant.value.id) : false));
const websiteUrl = computed(() => safeHttpUrl(restaurant.value?.website));

function labelFor(code: string, labels: Record<string, string>): string {
    return labels[code] ?? code;
}

async function load() {
    loading.value = true;
    notFound.value = false;
    try {
        const response = await client.get<ApiSuccess<Restaurant>>(`/restaurants/${props.id}`);
        restaurant.value = response.data.data;
    } catch (error: unknown) {
        if (isAxiosError(error) && error.response?.status === 404) {
            notFound.value = true;
            restaurant.value = null;
        } else {
            throw error;
        }
    } finally {
        loading.value = false;
    }
}

async function loadLookups() {
    const [dietsRes, featuresRes] = await Promise.all([
        client.get<ApiSuccess<DietType[]>>('/diets'),
        client.get<ApiSuccess<Feature[]>>('/features'),
    ]);
    dietLabels.value = Object.fromEntries(dietsRes.data.data.map((item) => [item.code, item.label]));
    featureLabels.value = Object.fromEntries(featuresRes.data.data.map((item) => [item.code, item.label]));
}

async function toggleFavorite() {
    if (!restaurant.value) return;
    if (isFavorite.value) {
        await favorites.remove(restaurant.value.id);
    } else {
        await favorites.add(restaurant.value.id);
    }
}

async function submitReview() {
    if (!restaurant.value) return;
    submittingReview.value = true;
    reviewError.value = null;
    try {
        await client.post(`/restaurants/${restaurant.value.id}/reviews`, {
            rating: reviewRating.value,
            comment: reviewComment.value || undefined,
        });
        reviewComment.value = '';
        await load();
    } catch (error: unknown) {
        reviewError.value = extractApiErrorMessage(error, '送出評論失敗');
    } finally {
        submittingReview.value = false;
    }
}

onMounted(() => {
    loadLookups();
    if (auth.isAuthenticated && !favorites.loaded) {
        favorites.fetchAll();
    }
});

watch(() => props.id, load, { immediate: true });
</script>

<template>
    <div class="restaurant-detail">
        <p v-if="loading">載入中…</p>
        <p v-else-if="notFound">找不到這間餐廳。</p>
        <template v-else-if="restaurant">
            <header>
                <h1>{{ restaurant.name }}</h1>
                <button v-if="auth.isAuthenticated" type="button" @click="toggleFavorite">
                    {{ isFavorite ? '★ 已收藏' : '☆ 加入收藏' }}
                </button>
            </header>

            <p class="rating">⭐ {{ restaurant.rating.toFixed(1) }}（{{ restaurant.rating_count }} 則評論）</p>
            <p v-if="restaurant.confidence_score !== null && restaurant.confidence_score !== undefined">
                素食可信度：{{ restaurant.confidence_score }} / 100
            </p>
            <p v-if="restaurant.address?.trim()">{{ restaurant.address }}</p>
            <p v-if="restaurant.phone">電話：{{ restaurant.phone }}</p>
            <p v-if="websiteUrl">
                <a :href="websiteUrl" target="_blank" rel="noopener noreferrer">官方網站</a>
            </p>
            <p v-if="restaurant.description">{{ restaurant.description }}</p>

            <div v-if="restaurant.diet_types?.length" class="tags">
                <span v-for="code in restaurant.diet_types" :key="code" class="tag">{{ labelFor(code, dietLabels) }}</span>
            </div>
            <div v-if="restaurant.features?.length" class="tags">
                <span v-for="code in restaurant.features" :key="code" class="tag feature">{{ labelFor(code, featureLabels) }}</span>
            </div>

            <section v-if="restaurant.menu_items?.length">
                <h2>菜單</h2>
                <ul>
                    <li v-for="item in restaurant.menu_items" :key="item.id">
                        {{ item.name }}
                        <span v-if="item.price !== null">NT$ {{ item.price }}</span>
                        <span class="diet-type">({{ labelFor(item.diet_type, dietLabels) }})</span>
                    </li>
                </ul>
            </section>

            <section class="review-form" v-if="auth.isAuthenticated">
                <h2>寫評論</h2>
                <label>
                    評分
                    <select v-model.number="reviewRating">
                        <option v-for="n in [5, 4, 3, 2, 1]" :key="n" :value="n">{{ n }}</option>
                    </select>
                </label>
                <textarea v-model="reviewComment" placeholder="分享你的用餐經驗（選填）"></textarea>
                <p v-if="reviewError" class="error">{{ reviewError }}</p>
                <button type="button" :disabled="submittingReview" @click="submitReview">
                    {{ submittingReview ? '送出中…' : '送出評論' }}
                </button>
            </section>
            <p v-else>
                <RouterLink to="/login">登入</RouterLink> 後可以收藏餐廳或寫評論。
            </p>
        </template>
    </div>
</template>

<style scoped>
.restaurant-detail {
    max-width: 720px;
    margin: 0 auto;
    padding: 1.5rem;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tags {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin: 0.5rem 0;
}

.tag {
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    background: #f0fff4;
    color: #2f855a;
    font-size: 0.8rem;
}

.tag.feature {
    background: #ebf8ff;
    color: #2b6cb0;
}

.review-form {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: 400px;
}

.review-form textarea {
    min-height: 80px;
    padding: 0.5rem;
}

.error {
    color: #c53030;
}

.diet-type {
    color: #718096;
    font-size: 0.8rem;
}
</style>
