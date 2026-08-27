<script setup lang="ts">
import { onMounted, ref } from 'vue';
import client from '@/api/client';
import type { ApiSuccess } from '@/types';

interface AdminReport {
    id: number;
    restaurant: { id: number; name: string };
    user: { id: number; name: string };
    type: string;
    description: string | null;
    status: string;
}

interface AdminReview {
    id: number;
    user: { id: number; name: string };
    rating: number;
    comment: string | null;
}

/** 重複審核的一組候選。同名且 100m 內的餐廳被歸在一起，見後端 §22 的說明。 */
interface DuplicateGroup {
    name: string;
    /** 只剩一筆＝同組的另一筆已經處理掉，這個標記是過期的。 */
    stale: boolean;
    restaurants: Array<{
        id: number;
        name: string;
        address: string;
        city: string | null;
        district: string | null;
        source: string;
        source_id: string | null;
        status: string;
    }>;
}

type Tab = 'reports' | 'reviews' | 'duplicates' | 'closures';

const tab = ref<Tab>('reports');
const duplicates = ref<DuplicateGroup[]>([]);

/**
 * 疑似歇業。偵測端（restaurants:check-closed）只寫訊號、不下架——OSM 節點消失
 * 也可能只是被合併進 way 或誤刪。這一頁是那些訊號唯一的出口。
 */
interface ClosureSignal {
    id: number;
    signal: string;
    metadata: Record<string, unknown> | null;
    detected_at: string | null;
    restaurant: {
        id: number;
        name: string;
        slug: string | null;
        address: string | null;
        city: string | null;
        status: string;
        website: string | null;
        google_maps_url: string;
    } | null;
}

const closures = ref<ClosureSignal[]>([]);

/** 訊號代碼對使用者沒有意義，翻成「憑什麼說它歇業了」。 */
const SIGNAL_LABELS: Record<string, string> = {
    osm_node_missing: 'OpenStreetMap 上查不到這個點位了',
};

async function loadClosures() {
    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<ClosureSignal[]>>('/admin/closures');
        closures.value = response.data.data;
    } finally {
        loading.value = false;
    }
}

async function resolveClosure(signal: ClosureSignal, resolution: 'confirmed' | 'dismissed') {
    await client.post(`/admin/closures/${signal.id}`, { resolution });
    await loadClosures();
}
const reports = ref<AdminReport[]>([]);
const reviews = ref<AdminReview[]>([]);
const loading = ref(false);

async function loadReports() {
    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<AdminReport[]>>('/admin/reports');
        reports.value = response.data.data;
    } finally {
        loading.value = false;
    }
}

async function loadReviews() {
    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<AdminReview[]>>('/admin/reviews', {
            params: { status: 'active' },
        });
        reviews.value = response.data.data;
    } finally {
        loading.value = false;
    }
}

async function loadDuplicates() {
    loading.value = true;
    try {
        const response = await client.get<ApiSuccess<DuplicateGroup[]>>('/admin/duplicates');
        duplicates.value = response.data.data;
    } finally {
        loading.value = false;
    }
}

/**
 * 只有「保留」與「下架」兩個動作，沒有合併——兩筆同名又相近，也可能是同一條街上
 * 的兩家分店，合併會把一家真實存在的店抹掉而且不可逆（見後端 Controller 註解）。
 */
async function resolveDuplicate(id: number, action: 'keep' | 'deactivate') {
    await client.post(`/admin/restaurants/${id}/duplicate`, { action });
    await loadDuplicates();
}

async function approve(report: AdminReport) {
    await client.post(`/admin/reports/${report.id}/approve`);
    await loadReports();
}

async function reject(report: AdminReport) {
    await client.post(`/admin/reports/${report.id}/reject`);
    await loadReports();
}

async function hide(review: AdminReview) {
    await client.post(`/admin/reviews/${review.id}/hide`);
    await loadReviews();
}

function switchTab(target: Tab) {
    tab.value = target;

    if (target === 'reports') loadReports();
    else if (target === 'reviews') loadReviews();
    else if (target === 'closures') loadClosures();
    else loadDuplicates();
}

onMounted(loadReports);
</script>

<template>
    <div class="admin">
        <h1>管理後台</h1>
        <nav class="tabs">
            <button type="button" :class="{ active: tab === 'reports' }" @click="switchTab('reports')">
                待審核回報
            </button>
            <button type="button" :class="{ active: tab === 'reviews' }" @click="switchTab('reviews')">
                評論管理
            </button>
            <button type="button" :class="{ active: tab === 'duplicates' }" @click="switchTab('duplicates')">
                重複審核
            </button>
            <button type="button" :class="{ active: tab === 'closures' }" @click="switchTab('closures')">
                疑似歇業
            </button>
        </nav>

        <p v-if="loading">載入中…</p>

        <ul v-if="tab === 'reports' && !loading">
            <li v-for="report in reports" :key="report.id">
                <strong>{{ report.restaurant.name }}</strong> — {{ report.type }}
                <p v-if="report.description">{{ report.description }}</p>
                <small>回報者：{{ report.user.name }}</small>
                <div class="actions">
                    <button type="button" @click="approve(report)">核准</button>
                    <button type="button" class="danger" @click="reject(report)">駁回</button>
                </div>
            </li>
            <p v-if="!reports.length">目前沒有待審核的回報。</p>
        </ul>

        <ul v-if="tab === 'reviews' && !loading">
            <li v-for="review in reviews" :key="review.id">
                <strong>{{ review.user.name }}</strong> ⭐ {{ review.rating }}
                <p v-if="review.comment">{{ review.comment }}</p>
                <div class="actions">
                    <button type="button" class="danger" @click="hide(review)">隱藏</button>
                </div>
            </li>
            <p v-if="!reviews.length">目前沒有評論。</p>
        </ul>

        <ul v-if="tab === 'closures' && !loading" class="closures">
            <li v-for="signal in closures" :key="signal.id">
                <strong>{{ signal.restaurant?.name ?? '（餐廳已不存在）' }}</strong>
                <p class="reason">{{ SIGNAL_LABELS[signal.signal] ?? signal.signal }}</p>
                <p v-if="signal.restaurant?.address" class="address">{{ signal.restaurant.address }}</p>
                <p v-if="signal.restaurant?.status !== 'active'" class="stale">
                    這家店已經是 {{ signal.restaurant?.status }}，可能已由別的流程處理過
                </p>
                <!--
                    判斷「這家店還在不在」最快的方式就是去 Google 地圖看一眼，
                    所以連結直接放在按鈕旁邊——不要逼 Admin 自己複製店名再搜尋。
                -->
                <p v-if="signal.restaurant" class="links">
                    <a :href="signal.restaurant.google_maps_url" target="_blank" rel="noopener noreferrer">
                        在 Google 地圖查看
                    </a>
                    <a
                        v-if="signal.restaurant.website"
                        :href="signal.restaurant.website"
                        target="_blank"
                        rel="noopener noreferrer"
                    >官方網站</a>
                </p>
                <div class="actions">
                    <button type="button" class="danger" @click="resolveClosure(signal, 'confirmed')">
                        確認歇業並下架
                    </button>
                    <button type="button" @click="resolveClosure(signal, 'dismissed')">
                        誤報，店還在
                    </button>
                </div>
            </li>
            <p v-if="!closures.length">目前沒有疑似歇業的店家。</p>
        </ul>

        <ul v-if="tab === 'duplicates' && !loading" class="duplicates">
            <li v-for="(group, index) in duplicates" :key="`${group.name}-${index}`">
                <strong>{{ group.name }}</strong>
                <span v-if="group.stale" class="stale">同組的另一筆已處理，這個標記已過期</span>
                <table>
                    <tbody>
                        <tr v-for="restaurant in group.restaurants" :key="restaurant.id">
                            <td>
                                {{ restaurant.address || '地址未提供' }}
                                <small>{{ restaurant.source }}{{ restaurant.source_id ? ` #${restaurant.source_id}` : '' }}・{{ restaurant.status }}</small>
                            </td>
                            <td class="actions">
                                <button type="button" @click="resolveDuplicate(restaurant.id, 'keep')">保留</button>
                                <button
                                    type="button"
                                    class="danger"
                                    @click="resolveDuplicate(restaurant.id, 'deactivate')"
                                >
                                    下架
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </li>
            <p v-if="!duplicates.length">目前沒有被標記為可能重複的餐廳。</p>
        </ul>
    </div>
</template>

<style scoped>
.admin {
    max-width: 720px;
    margin: 0 auto;
    padding: 1.5rem;
}

.tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.tabs button {
    padding: 0.5rem 1rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
}

.tabs button.active {
    background: #2f855a;
    color: #fff;
    border-color: #2f855a;
}

ul {
    list-style: none;
    padding: 0;
}

li {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.actions button {
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    border: 1px solid #cbd5e0;
    background: #fff;
    cursor: pointer;
}

.actions .danger {
    color: #c53030;
    border-color: #feb2b2;
}

.duplicates table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0.5rem;
}

.duplicates td {
    padding: 0.35rem 0;
    vertical-align: top;
    border-top: 1px solid #edf2f7;
}

.duplicates small {
    display: block;
    color: #718096;
}

.duplicates .stale {
    margin-left: 0.5rem;
    color: #975a16;
    font-size: 0.85rem;
}
</style>
