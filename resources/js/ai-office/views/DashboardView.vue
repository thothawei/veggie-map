<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AiOfficeShell from '../components/AiOfficeShell.vue';
import CommandCenter from '../components/dashboard/CommandCenter.vue';
import StatisticsPanel from '../components/dashboard/StatisticsPanel.vue';
import ApprovalPanel from '../components/dashboard/ApprovalPanel.vue';
import OfficeMap from '../components/office/OfficeMap.vue';
import { useAgentsStore } from '../stores/agents';
import { useApprovalsStore } from '../stores/approvals';
import { useProjectsStore } from '../stores/projects';
import { useAuthStore } from '@/stores/auth';
import { extractApiErrorMessage } from '@/lib/apiError';
import { fetchDashboard } from '../api/dashboard';
import type { AiOfficeDashboard } from '../types';

const router = useRouter();
const auth = useAuthStore();
const projects = useProjectsStore();
const agents = useAgentsStore();
const approvals = useApprovalsStore();

const createError = ref<string | null>(null);

const canWrite = computed(() => ['admin', 'manager', 'developer'].includes(auth.user?.role ?? ''));
const canApprove = computed(() => ['admin', 'manager'].includes(auth.user?.role ?? ''));

/**
 * 規格第 38 節要的四個數字是「今日：完成任務／等待處理／錯誤／執行中」，而且
 * 第 74 節明講不可以是假的。
 *
 * 先前這裡是 `projects.projects.length` 這種前端自己數分頁清單的做法——數字會隨著
 * 「載入了幾頁」變動，而且數的根本不是規格要的那四個。改成由 `GET /dashboard`
 * 算好回傳。
 *
 * 端點失敗時整排不顯示（`stats` 是空陣列），不用 0 佔位——「載入失敗」跟
 * 「今天沒有完成任何任務」是兩件事。
 */
const dashboard = ref<AiOfficeDashboard | null>(null);

const stats = computed(() => {
    const data = dashboard.value;

    if (!data) {
        return [];
    }

    return [
        { label: '今日完成任務', value: data.today.completed },
        {
            label: '等待處理',
            value: data.today.waiting,
            tone: data.today.waiting > 0 ? ('warn' as const) : undefined,
        },
        {
            label: '今日錯誤',
            value: data.today.errors,
            tone: data.today.errors > 0 ? ('danger' as const) : undefined,
        },
        { label: '執行中', value: data.today.running },
    ];
});

async function loadDashboard() {
    try {
        dashboard.value = await fetchDashboard();
    } catch {
        dashboard.value = null;
    }
}

async function create(payload: { name: string; description: string | null }) {
    createError.value = null;
    try {
        await projects.create(payload);
    } catch (error: unknown) {
        createError.value = extractApiErrorMessage(error, '建立專案失敗');
    }
}

async function decide(payload: { id: number; decision: 'approve' | 'reject'; comment: string | null }) {
    await approvals.decide(payload.id, payload.decision, payload.comment ?? undefined);
}

function openProject(id: number) {
    void router.push({ name: 'ai-office-project', params: { id: String(id) } });
}

onMounted(() => {
    void loadDashboard();
    void projects.fetchAll();
    void agents.fetchAll();
    void approvals.fetchPending();
});
</script>

<template>
    <AiOfficeShell title="AI Office 總覽">
        <StatisticsPanel v-if="stats.length" :items="stats" />

        <p v-if="createError" class="error" role="alert">{{ createError }}</p>

        <div class="grid">
            <CommandCenter
                class="panel"
                :projects="projects.projects"
                :loading="projects.loading"
                :can-create="canWrite"
                @create="create"
                @open="openProject"
            />
            <ApprovalPanel
                class="panel"
                :approvals="approvals.approvals"
                :loading="approvals.loading"
                :can-decide="canApprove"
                @decide="decide"
            />
        </div>

        <!-- 總覽沒有專案脈絡，所以只畫誰在什麼狀態，不畫在做哪個任務。 -->
        <OfficeMap
            class="panel agents"
            :agents="agents.agents"
            :loading="agents.loading"
            @select="router.push({ name: 'ai-office-agents' })"
        />
    </AiOfficeShell>
</template>

<style scoped>
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.agents {
    margin-top: 0.75rem;
}

.error {
    color: #f2777a;
    font-size: 0.85rem;
}
</style>
