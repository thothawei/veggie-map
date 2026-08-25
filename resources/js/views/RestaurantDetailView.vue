<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { isAxiosError } from 'axios';
import client from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import { extractApiErrorMessage } from '@/lib/apiError';
import { applyMenuItemDiets, menuItemDiets } from '@/lib/dietCatalog';
import { formatAddress, formatCuisines, formatOpenStatus } from '@/lib/format';
import { safeHttpUrl } from '@/lib/redirect';
import type { AdminVerificationType, ApiSuccess, DietType, Feature, MenuItem, MenuItemDiet, Restaurant } from '@/types';

const props = defineProps<{ id: string }>();

const auth = useAuthStore();

const restaurant = ref<Restaurant | null>(null);
const loading = ref(true);
const notFound = ref(false);
const loadError = ref<string | null>(null);
const dietLabels = ref<Record<string, string>>({});
const featureLabels = ref<Record<string, string>>({});
const dishDiets = ref<MenuItemDiet[]>([]);

const newItem = reactive({ name: '', price: '', diet_type: '' });
const addingItem = ref(false);
const addItemError = ref<string | null>(null);

const verificationTypes = ref<AdminVerificationType[]>([]);
const newVerification = reactive({ type: '' });
const savingVerification = ref(false);
const verificationError = ref<string | null>(null);
const verificationNotice = ref<string | null>(null);

const websiteUrl = computed(() => safeHttpUrl(restaurant.value?.website));
const displayAddress = computed(() => (restaurant.value ? formatAddress(restaurant.value) : null));
const cuisineLine = computed(() => formatCuisines(restaurant.value?.cuisines));
const openStatus = computed(() => (restaurant.value ? formatOpenStatus(restaurant.value) : null));

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
            </header>

            <p v-if="restaurant.venue_badge" class="venue-line">
                <span class="venue-badge" :data-kind="restaurant.venue_kind ?? undefined">{{ restaurant.venue_badge }}</span>
                <span v-if="restaurant.venue_summary">{{ restaurant.venue_summary }}</span>
            </p>

            <dl class="facts">
                <div>
                    <dt>地址</dt>
                    <dd>{{ displayAddress ?? '地址未提供' }}</dd>
                </div>
                <div v-if="cuisineLine">
                    <dt>料理</dt>
                    <dd>{{ cuisineLine }}</dd>
                </div>
                <div v-if="restaurant.phone">
                    <dt>電話</dt>
                    <dd>{{ restaurant.phone }}</dd>
                </div>
            </dl>
            <section class="hours">
                <h2>營業時間</h2>
                <p
                    v-if="openStatus"
                    class="open-status"
                    :data-state="openStatus.state"
                >{{ openStatus.text }}</p>
                <table v-if="restaurant.opening_hours_week?.length">
                    <tbody>
                        <tr v-for="day in restaurant.opening_hours_week" :key="day.day">
                            <th scope="row">{{ day.label }}</th>
                            <td>{{ day.ranges.length ? day.ranges.join('、') : '公休' }}</td>
                        </tr>
                    </tbody>
                </table>
                <!--
                    沒有可解析的營業時間就照實說。OSM 多數店家沒填 opening_hours，
                    這裡不要留白讓人以為是載入失敗，也不要編一組時間。
                -->
                <p v-else class="unknown">
                    尚未取得營業時間資料<span v-if="restaurant.opening_hours_raw">（原始標示：{{ restaurant.opening_hours_raw }}）</span>。
                </p>
            </section>

            <p v-if="websiteUrl">
                <a :href="websiteUrl" target="_blank" rel="noopener noreferrer">官方網站</a>
            </p>
            <p v-if="restaurant.description">{{ restaurant.description }}</p>

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

.facts {
    display: grid;
    gap: 0.65rem;
    margin: 1rem 0;
}

.facts div {
    display: grid;
    grid-template-columns: 3.5rem 1fr;
    gap: 0.75rem;
    align-items: baseline;
}

.facts dt {
    margin: 0;
    color: #718096;
    font-size: 0.85rem;
}

.facts dd {
    margin: 0;
    font-size: 1rem;
    color: #1a202c;
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

.hours table {
    border-collapse: collapse;
    font-size: 0.9rem;
}

.hours th {
    text-align: left;
    font-weight: 500;
    color: #4a5568;
    padding: 0.15rem 1rem 0.15rem 0;
}

.hours td {
    padding: 0.15rem 0;
}

.hours .unknown {
    color: #718096;
    font-size: 0.9rem;
}

.open-status[data-state='open'] {
    color: #2f855a;
    font-weight: 600;
}

.open-status[data-state='closed'] {
    color: #718096;
}
</style>
