<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import client from '@/api/client';
import { useAuthStore } from '@/stores/auth';
import { extractApiErrorMessage } from '@/lib/apiError';
import { applyMenuItemDiets, menuItemDiets } from '@/lib/dietCatalog';
import { formatAddress, formatCuisines, formatDistance, formatOpenStatus } from '@/lib/format';
import { safeHttpUrl } from '@/lib/redirect';
import type { AdminVerificationType, ApiSuccess, DietType, Feature, MenuItem, MenuItemDiet, Restaurant } from '@/types';

const props = defineProps<{ id: string }>();

const auth = useAuthStore();
const router = useRouter();

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

/** 附近搜尋的範圍與筆數。2km 是走得到的距離；六筆一排排得下也不會搶走主內容。 */
const NEARBY_RADIUS_KM = 2;
const NEARBY_LIMIT = 6;

const nearby = ref<Restaurant[]>([]);

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

/**
 * 「附近的素食餐廳」。
 *
 * 看到一家不合適的店（今天公休、不是全素、太貴）時，使用者的下一步幾乎一定是
 * 「那附近還有什麼」——原本得自己退回地圖再找一次。復用同一支搜尋 API，不另外
 * 做一個推薦端點。
 *
 * `venue_scope=all`：這裡的目的是「附近還有哪些選擇」，把素食友善店排除掉會讓
 * 這個區塊在很多地方變成空的。卡片本身會標示是純素食店還是素食友善。
 */
async function loadNearby(current: Restaurant) {
    nearby.value = [];

    try {
        const response = await client.get<ApiSuccess<Restaurant[]>>('/restaurants', {
            params: {
                latitude: current.latitude,
                longitude: current.longitude,
                radius: NEARBY_RADIUS_KM,
                sort: 'distance',
                per_page: NEARBY_LIMIT + 1,
                venue_scope: 'all',
            },
        });

        // 半徑搜尋一定會撈到自己（距離 0），要濾掉；多要一筆就是為了濾掉之後
        // 仍然湊得滿。
        nearby.value = response.data.data
            .filter((item) => item.id !== current.id)
            .slice(0, NEARBY_LIMIT);
    } catch {
        // 這是輔助區塊，失敗就整段不顯示。為了它在詳情頁跳一個紅色錯誤，
        // 會讓使用者以為主要內容也壞了。
        nearby.value = [];
    }
}

/**
 * 用舊 slug（restaurant_slug_aliases）進來時，後端仍然回這家店，但網址還是舊的。
 * 換成現行 slug，之後分享出去的才是正牌網址。
 *
 * 只處理「非數字的 id 參數且與現行 slug 不同」＝別名那條路；數字 id 的連結刻意
 * 留著不動（第二十六節說舊的數字連結仍然有效）。
 */
function replaceWithCanonicalSlug(loaded: Restaurant) {
    const requested = props.id;
    if (!loaded.slug || /^\d+$/.test(requested) || requested === loaded.slug) return;

    void router.replace({ name: 'restaurant-detail', params: { id: loaded.slug } });
}

async function load() {
    loading.value = true;
    notFound.value = false;
    loadError.value = null;
    try {
        const response = await client.get<ApiSuccess<Restaurant>>(`/restaurants/${props.id}`);
        restaurant.value = response.data.data;
        replaceWithCanonicalSlug(response.data.data);
        await loadNearby(response.data.data);
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
            <section v-if="restaurant.confidence_score" class="confidence">
                <h2>素食可信度 {{ restaurant.confidence_score }}／100</h2>
                <!--
                  只給一個數字的話，使用者沒辦法判斷要不要相信它——「管理員已查證」
                  跟「OSM 標示」是很不一樣的證據。
                -->
                <ul v-if="restaurant.confidence_breakdown?.length">
                    <li v-for="item in restaurant.confidence_breakdown" :key="item.code">
                        {{ item.label }}<span class="points">+{{ item.score }}</span>
                    </li>
                </ul>
                <p v-else class="unknown">目前沒有有效的查證紀錄。</p>
            </section>

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

            <section v-if="nearby.length" class="nearby">
                <h2>附近的素食餐廳</h2>
                <ul>
                    <li v-for="item in nearby" :key="item.id">
                        <RouterLink :to="{ name: 'restaurant-detail', params: { id: item.slug ?? item.id } }">
                            <strong>{{ item.name }}</strong>
                            <span v-if="item.venue_badge" class="venue-badge">{{ item.venue_badge }}</span>
                            <span v-if="formatDistance(item.distance_meters)" class="distance">
                                {{ formatDistance(item.distance_meters) }}
                            </span>
                            <span
                                v-if="formatOpenStatus(item)"
                                class="open-status"
                                :data-state="formatOpenStatus(item)?.state"
                            >{{ formatOpenStatus(item)?.text }}</span>
                        </RouterLink>
                    </li>
                </ul>
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

.nearby ul {
    list-style: none;
    padding: 0;
    display: grid;
    gap: 0.5rem;
}

.nearby a {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}

.nearby a:hover {
    border-color: #2f855a;
}

.nearby .distance {
    color: #718096;
    font-size: 0.85rem;
}

.confidence ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    gap: 0.25rem;
    font-size: 0.9rem;
}

.confidence .points {
    margin-left: 0.5rem;
    color: #2f855a;
    font-variant-numeric: tabular-nums;
}

.confidence .unknown {
    color: #718096;
    font-size: 0.9rem;
}
</style>
