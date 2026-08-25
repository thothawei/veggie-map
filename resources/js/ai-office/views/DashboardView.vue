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

const router = useRouter();
const auth = useAuthStore();
const projects = useProjectsStore();
const agents = useAgentsStore();
const approvals = useApprovalsStore();

const createError = ref<string | null>(null);

const canWrite = computed(() => ['admin', 'manager', 'developer'].includes(auth.user?.role ?? ''));
const canApprove = computed(() => ['admin', 'manager'].includes(auth.user?.role ?? ''));

const stats = computed(() => [
    { label: '專案', value: projects.projects.length },
    { label: '進行中專案', value: projects.countByStatus.active ?? 0 },
    { label: '工作中的 Agent', value: agents.busyCount },
    { label: '待核准', value: approvals.pendingCount, tone: approvals.pendingCount > 0 ? 'warn' as const : undefined },
]);

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
    void projects.fetchAll();
    void agents.fetchAll();
    void approvals.fetchPending();
});
</script>

<template>
    <AiOfficeShell title="AI Office 總覽">
        <StatisticsPanel :items="stats" />

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
