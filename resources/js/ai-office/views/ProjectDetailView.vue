<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AiOfficeShell from '../components/AiOfficeShell.vue';
import ActivityFeed from '../components/dashboard/ActivityFeed.vue';
import MessageFeed from '../components/dashboard/MessageFeed.vue';
import TaskBoard from '../components/task/TaskBoard.vue';
import TaskDetail from '../components/task/TaskDetail.vue';
import StatisticsPanel from '../components/dashboard/StatisticsPanel.vue';
import OfficeMap from '../components/office/OfficeMap.vue';
import { useActivityStream } from '../composables/useActivityStream';
import { listMessages } from '../api/messages';
import type { AiOfficeMessage } from '../types';
import { useAgentsStore } from '../stores/agents';
import { useProjectsStore } from '../stores/projects';
import { useTasksStore } from '../stores/tasks';
import { PROJECT_STATUS_LABELS } from '../labels';
import { useAuthStore } from '@/stores/auth';
import { extractApiErrorMessage } from '@/lib/apiError';

const props = defineProps<{ id: string }>();

const router = useRouter();
const auth = useAuthStore();
const projects = useProjectsStore();
const tasks = useTasksStore();
const agents = useAgentsStore();
const {
    activities,
    state: streamState,
    error: streamError,
    start: startStream,
    stop: stopStream,
} = useActivityStream();

const actionError = ref<string | null>(null);
const projectId = computed(() => Number(props.id));
const canWrite = computed(() => ['admin', 'manager', 'developer'].includes(auth.user?.role ?? ''));

const selectedAgentName = computed(() => {
    const agentId = tasks.selected?.assigned_agent_id;

    return agentId ? (agents.agents.find((agent) => agent.id === agentId)?.name ?? null) : null;
});

const stats = computed(() => {
    const byStatus = tasks.byStatus;

    return [
        { label: '任務總數', value: tasks.tasks.length },
        { label: '執行中', value: byStatus.running.length },
        { label: '已完成', value: byStatus.completed.length + byStatus.approved.length },
        {
            label: '失敗',
            value: byStatus.failed.length,
            tone: byStatus.failed.length > 0 ? ('danger' as const) : undefined,
        },
    ];
});

/**
 * 事件流是唯一的即時來源，但事件本身只說「發生了什麼」，不帶任務的完整內容。
 * 收到跟任務／Agent 有關的事件就重抓一次任務清單——這比在前端自己套用 payload
 * 去猜新狀態安全：真相在後端，前端猜錯就會顯示一個資料庫裡不存在的狀態。
 */
watch(() => activities.value.length, () => {
    const latest = activities.value[0];

    if (latest && (latest.type.startsWith('Task') || latest.type.startsWith('Agent'))) {
        void tasks.fetchForProject(projectId.value);
        void agents.fetchAll();
    }
});

function openAgents() {
    void router.push({ name: 'ai-office-agents' });
}

async function selectTask(taskId: number) {
    actionError.value = null;
    try {
        await tasks.select(taskId);
    } catch (error: unknown) {
        actionError.value = extractApiErrorMessage(error, '載入任務失敗');
    }
}

async function cancelTask(taskId: number) {
    actionError.value = null;
    try {
        const task = tasks.tasks.find((item) => item.id === taskId);

        if (task) {
            await tasks.changeStatus(task, 'cancelled');
        }
    } catch (error: unknown) {
        actionError.value = extractApiErrorMessage(error, '取消任務失敗');
    }
}

/**
 * Agent 之間的訊息（規格第 34 節）。跟著事件流一起更新——訊息本身也會產生一則
 * `MessageSent` 活動，所以有新活動時就去補新訊息，不用另外開一條 SSE。
 */
const messages = ref<AiOfficeMessage[]>([]);

async function loadMessages() {
    try {
        const lastId = messages.value[messages.value.length - 1]?.id ?? 0;
        const fresh = await listMessages(projectId.value, lastId);

        messages.value = lastId > 0 ? [...messages.value, ...fresh] : fresh;
    } catch {
        // 輔助面板，失敗就維持現狀，不打斷主畫面。
    }
}

watch(() => activities.value.length, () => void loadMessages());

onMounted(() => {
    void loadMessages();
    void projects.fetchOne(projectId.value);
    void tasks.fetchForProject(projectId.value);
    void agents.fetchAll();
    startStream(projectId.value);
});

// 離開頁面沒關掉的話，EventSource 會一直佔著後端的連線名額（每人預設 3 條）。
onUnmounted(() => stopStream());
</script>

<template>
    <AiOfficeShell :title="projects.current?.name ?? '專案'">
        <p v-if="projects.current" class="status">
            狀態：{{ PROJECT_STATUS_LABELS[projects.current.status] ?? projects.current.status }}
        </p>
        <p v-if="projects.error" class="error" role="alert">{{ projects.error }}</p>
        <p v-if="actionError" class="error" role="alert">{{ actionError }}</p>

        <StatisticsPanel :items="stats" />

        <!-- 這裡有專案脈絡，所以桌上會顯示那位 Agent 正在跑的任務；
             資料跟看板同一份，不會出現「小人在忙、看板卻沒有 running」。 -->
        <OfficeMap
            class="panel office"
            :agents="agents.agents"
            :tasks="tasks.tasks"
            :loading="agents.loading"
            @select="openAgents"
        />

        <div class="board-area">
            <TaskBoard
                class="board"
                :tasks="tasks.tasks"
                :agents="agents.agents"
                :selected-id="tasks.selected?.id ?? null"
                @select="selectTask"
            />
            <TaskDetail
                v-if="tasks.selected"
                class="detail"
                :task="tasks.selected"
                :agent-name="selectedAgentName"
                :can-edit="canWrite"
                @close="tasks.clearSelection()"
                @cancel="cancelTask"
            />
        </div>

        <MessageFeed class="panel feed" :messages="messages" />

        <ActivityFeed
            class="panel feed"
            :activities="activities"
            :state="streamState"
            :error="streamError"
        />
    </AiOfficeShell>
</template>

<style scoped>
.status {
    margin: 0 0 0.75rem;
    color: var(--ai-muted);
    font-size: 0.85rem;
}

.board-area {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0.75rem;
    margin-top: 0.75rem;
}

@media (min-width: 900px) {
    .board-area {
        grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr);
    }
}

.office {
    margin-top: 0.75rem;
}

.feed {
    margin-top: 0.75rem;
}

.error {
    color: #f2777a;
    font-size: 0.85rem;
}
</style>
