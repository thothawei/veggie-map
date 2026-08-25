<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import client from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import { useFavoritesStore } from '@/stores/favorites';
import { extractApiErrorMessage } from '@/lib/apiError';
import { applyMenuItemDiets, menuItemDiets } from '@/lib/dietCatalog';
import { safeHttpUrl } from '@/lib/redirect';
import type { AdminVerificationType, ApiSuccess, DietType, Feature, MenuItem, MenuItemDiet, Restaurant } from '@/types';

const props = defineProps<{ id: string }>();

const auth = useAuthStore();
const favorites = useFavoritesStore();

const restaurant = ref<Restaurant | null>(null);
const loading = ref(true);
const notFound = ref(false);
const loadError = ref<string | null>(null);
const dietLabels = ref<Record<string, string>>({});
const featureLabels = ref<Record<string, string>>({});
const dishDiets = ref<MenuItemDiet[]>([]);

const reviewRating = ref(5);
const reviewComment = ref('');
const submittingReview = ref(false);
const reviewError = ref<string | null>(null);

const newItem = reactive({ name: '', price: '', diet_type: '' });
const addingItem = ref(false);
const addItemError = ref<string | null>(null);

const verificationTypes = ref<AdminVerificationType[]>([]);
const newVerification = reactive({ type: '' });
const savingVerification = ref(false);
const verificationError = ref<string | null>(null);
const verificationNotice = ref<string | null>(null);

const isFavorite = computed(() => (restaurant.value ? favorites.isFavorite(restaurant.value.id) : false));
const websiteUrl = computed(() => safeHttpUrl(restaurant.value?.website));

const menuGroups = computed(() => {
    const items = restaurant.value?.menu_items ?? [];
    if (items.length === 0) {
        return [];
    }

    const groups = new Map<string, { code: string; label: string; items: MenuItem[] }>();
    for (const diet of dishDiets.value) {
        groups.set(diet.code, { code: diet.code, label: diet.label, items: [] });
    }

    for (const item of items) {
        const existing = groups.get(item.diet_type);
        if (existing) {
            existing.items.push(item);
            continue;
        }

        groups.set(item.diet_type, {
            code: item.diet_type,
            label: item.diet_label || item.diet_type,
            items: [item],
        });
    }

    return [...groups.values()].filter((group) => group.items.length > 0);
});

function labelFor(code: string, labels: Record<string, string>): string {
    return labels[code] ?? code;
}

async function load() {
    loading.value = true;
    notFound.value = false;
    loadError.value = null;
    reviewComment.value = '';
    try {
        const response = await client.get<ApiSuccess<Restaurant>>(`/restaurants/${props.id}`);
        restaurant.value = response.data.data;
    } catch (error: unknown) {
        restaurant.value = null;
        if (isAxiosError(error) && error.response?.status === 404) {
            notFound.value = true;
        } else {
            loadError.value = extractApiErrorMessage(error, '載入餐廳失敗，請稍後再試。');
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
    applyMenuItemDiets(dietsRes.data.meta?.menu_item_diets as MenuItemDiet[] | undefined);
    dishDiets.value = menuItemDiets();
    if (!newItem.diet_type && dishDiets.value[0]) {
        newItem.diet_type = dishDiets.value[0].code;
    }
}

/** 可寫的驗證類型只有 admin 拿得到（端點本身擋 403），所以不是 admin 就不打。 */
async function loadVerificationTypes() {
    if (!auth.isAdmin) return;
    try {
        const response = await client.get<ApiSuccess<AdminVerificationType[]>>('/admin/verification-types');
        verificationTypes.value = response.data.data;
        if (!newVerification.type && verificationTypes.value[0]) {
            newVerification.type = verificationTypes.value[0].code;
        }
    } catch (error: unknown) {
        verificationError.value = extractApiErrorMessage(error, '載入驗證類型失敗');
    }
}

async function submitVerification() {
    if (!restaurant.value || !newVerification.type) return;
    savingVerification.value = true;
    verificationError.value = null;
    verificationNotice.value = null;
    try {
        await client.post(`/admin/restaurants/${restaurant.value.id}/verifications`, {
            verification_type: newVerification.type,
        });
        verificationNotice.value = '已記錄驗證，可信度重新計算完成。';
        await load();
    } catch (error: unknown) {
        verificationError.value = extractApiErrorMessage(error, '記錄驗證失敗');
    } finally {
        savingVerification.value = false;
    }
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

async function submitMenuItem() {
    if (!restaurant.value || !newItem.name.trim()) return;
    addingItem.value = true;
    addItemError.value = null;
    try {
        await client.post(`/admin/restaurants/${restaurant.value.id}/menu-items`, {
            name: newItem.name.trim(),
            diet_type: newItem.diet_type,
            price: newItem.price === '' ? undefined : Number(newItem.price),
        });
        newItem.name = '';
        newItem.price = '';
        await load();
    } catch (error: unknown) {
        addItemError.value = extractApiErrorMessage(error, '新增菜單失敗');
    } finally {
        addingItem.value = false;
    }
}

onMounted(() => {
    loadLookups();
    loadVerificationTypes();
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
        <p v-else-if="loadError" class="error">{{ loadError }}</p>
        <template v-else-if="restaurant">
            <header>
                <h1>{{ restaurant.name }}</h1>
                <button v-if="auth.isAuthenticated" type="button" @click="toggleFavorite">
                    {{ isFavorite ? '★ 已收藏' : '☆ 加入收藏' }}
                </button>
            </header>

            <p class="rating">
                <template v-if="restaurant.rating_count">
                    ⭐ {{ restaurant.rating.toFixed(1) }}（{{ restaurant.rating_count }} 則評論）
                </template>
                <template v-else>尚無評分</template>
            </p>
            <p v-if="restaurant.confidence_score !== null && restaurant.confidence_score !== undefined">
                素食可信度：{{ restaurant.confidence_score }} / 100
            </p>

            <form v-if="auth.isAdmin && verificationTypes.length" class="verify-form" @submit.prevent="submitVerification">
                <label>
                    標記驗證
                    <select v-model="newVerification.type">
                        <option v-for="type in verificationTypes" :key="type.code" :value="type.code">
                            {{ type.label }}（+{{ type.score }}）
                        </option>
                    </select>
                </label>
                <button type="submit" :disabled="savingVerification">
                    {{ savingVerification ? '記錄中…' : '記錄驗證' }}
                </button>
                <p v-if="verificationError" class="error">{{ verificationError }}</p>
                <p v-else-if="verificationNotice" role="status" class="notice">{{ verificationNotice }}</p>
            </form>
            <p v-if="restaurant.address?.trim()">{{ restaurant.address }}</p>
            <p v-if="restaurant.phone">電話：{{ restaurant.phone }}</p>
            <p v-if="websiteUrl">
                <a :href="websiteUrl" target="_blank" rel="noopener noreferrer">官方網站</a>
            </p>
            <p v-if="restaurant.description">{{ restaurant.description }}</p>

            <p v-if="restaurant.venue_badge" class="venue-line">
                <span class="venue-badge" :data-kind="restaurant.venue_kind ?? undefined">{{ restaurant.venue_badge }}</span>
                <span v-if="restaurant.venue_summary">{{ restaurant.venue_summary }}</span>
            </p>

            <div v-if="restaurant.diet_types?.length" class="tags">
                <span v-for="code in restaurant.diet_types" :key="code" class="tag">{{ labelFor(code, dietLabels) }}</span>
            </div>
            <div v-if="restaurant.features?.length" class="tags">
                <span v-for="code in restaurant.features" :key="code" class="tag feature">{{ labelFor(code, featureLabels) }}</span>
            </div>

            <section class="menu">
                <h2>菜單</h2>
                <template v-if="menuGroups.length">
                    <section v-for="group in menuGroups" :key="group.code" class="menu-group">
                        <h3>{{ group.label }}</h3>
                        <ul>
                            <li v-for="item in group.items" :key="item.id">
                                {{ item.name }}
                                <span v-if="item.price !== null">NT$ {{ item.price }}</span>
                            </li>
                        </ul>
                    </section>
                </template>
                <p v-else role="status" class="menu-empty">
                    {{ restaurant.menu_empty_message }}
                </p>

                <form v-if="auth.isAdmin" class="menu-form" @submit.prevent="submitMenuItem">
                    <h3>新增菜色</h3>
                    <label>
                        名稱
                        <input v-model="newItem.name" type="text" required maxlength="255" />
                    </label>
                    <label>
                        葷素
                        <select v-model="newItem.diet_type">
                            <option v-for="diet in dishDiets" :key="diet.code" :value="diet.code">
                                {{ diet.label }}
                            </option>
                        </select>
                    </label>
                    <label>
                        價格
                        <input v-model="newItem.price" type="number" min="0" step="1" />
                    </label>
                    <p v-if="addItemError" class="error">{{ addItemError }}</p>
                    <button type="submit" :disabled="addingItem">
                        {{ addingItem ? '新增中…' : '新增' }}
                    </button>
                </form>
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
                <RouterLink :to="{ name: 'login', query: { redirect: `/restaurants/${restaurant.id}` } }">登入</RouterLink>
                後可以收藏餐廳或寫評論。
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

.venue-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin: 0.5rem 0;
    color: #4a5568;
}

.venue-badge {
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    background: #f0fff4;
    color: #276749;
    font-size: 0.85rem;
}

.venue-badge[data-kind='friendly'] {
    background: #ebf8ff;
    color: #2b6cb0;
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

.menu-group {
    margin: 0.75rem 0;
}

.menu-group h3 {
    margin: 0 0 0.35rem;
    font-size: 1rem;
    color: #2f855a;
}

.menu-empty {
    color: #4a5568;
}

.menu-form {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: 400px;
    margin-top: 1rem;
}

.verify-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin: 0.5rem 0 1rem;
}

.notice {
    color: #2f855a;
}

.menu-form input,
.menu-form select {
    display: block;
    width: 100%;
    margin-top: 0.2rem;
    padding: 0.35rem;
}
</style>
