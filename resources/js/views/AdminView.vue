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

const tab = ref<'reports' | 'reviews'>('reports');
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

function switchTab(target: 'reports' | 'reviews') {
    tab.value = target;
    if (target === 'reports') loadReports();
    else loadReviews();
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
</style>
