<script setup lang="ts">
import { computed } from 'vue';
import OfficeRoom from './OfficeRoom.vue';
import type { AiOfficeAgent, AiOfficeTask } from '../../types';

const props = defineProps<{
    agents: AiOfficeAgent[];
    /** 專案的任務清單。沒有傳（例如總覽頁沒有專案脈絡）就只畫狀態，不畫在做什麼。 */
    tasks?: AiOfficeTask[];
    loading?: boolean;
}>();

const emit = defineEmits<{ (event: 'select', agentId: number): void }>();

/** 房間名稱。對不上的角色會自己開一間，用角色代碼當標題，不會被吞掉。 */
const ROOM_TITLES: Record<string, string> = {
    ceo: 'CEO 辦公室',
    backend: '後端',
    frontend: '前端',
    qa: 'QA',
    design: '設計',
    devops: 'DevOps',
    automation: '自動化',
};

/** CEO 永遠排第一間，其餘照 ROOM_TITLES 的順序，未知角色排最後。 */
const ROOM_ORDER = Object.keys(ROOM_TITLES);

const rooms = computed(() => {
    const grouped = new Map<string, AiOfficeAgent[]>();

    props.agents.forEach((agent) => {
        const list = grouped.get(agent.role) ?? [];
        list.push(agent);
        grouped.set(agent.role, list);
    });

    return [...grouped.entries()]
        .sort(([a], [b]) => {
            const indexA = ROOM_ORDER.indexOf(a);
            const indexB = ROOM_ORDER.indexOf(b);

            return (indexA === -1 ? ROOM_ORDER.length : indexA) - (indexB === -1 ? ROOM_ORDER.length : indexB);
        })
        .map(([role, agents]) => ({ role, title: ROOM_TITLES[role] ?? role, agents }));
});

/**
 * 「誰正在做什麼」只認 status=running 的任務。用 assigned_agent_id 反查，
 * 一個 Agent 同時有多個 running 時取 id 最小的那個（最早派的），不隨機挑。
 */
const tasksByAgent = computed(() => {
    const map = new Map<number, AiOfficeTask>();

    [...(props.tasks ?? [])]
        .filter((task) => task.status === 'running' && task.assigned_agent_id !== null)
        .sort((a, b) => a.id - b.id)
        .forEach((task) => {
            const agentId = task.assigned_agent_id as number;

            if (!map.has(agentId)) {
                map.set(agentId, task);
            }
        });

    return map;
});
</script>

<template>
    <section class="office-map">
        <header>
            <h2>Pixel Office</h2>
            <span class="hint">點一位 Agent 看細節</span>
        </header>

        <p v-if="loading" class="empty">載入中…</p>
        <p v-else-if="rooms.length === 0" class="empty">辦公室還沒有人。跑 AgentSeeder 建立初始團隊。</p>

        <div v-else class="rooms">
            <OfficeRoom
                v-for="room in rooms"
                :key="room.role"
                :title="room.title"
                :agents="room.agents"
                :tasks-by-agent="tasksByAgent"
                @select="emit('select', $event)"
            />
        </div>
    </section>
</template>

<style scoped>
header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.5rem;
}

h2 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
}

.hint,
.empty {
    color: var(--ai-muted);
    font-size: 0.8rem;
}

.rooms {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    gap: 0.5rem;
}
</style>
