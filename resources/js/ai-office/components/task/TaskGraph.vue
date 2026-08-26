<script setup lang="ts">
/**
 * 任務相依的 DAG 視覺化（規格第 44／49 節）。
 *
 * 規格明講「第一版不需要非常複雜，重點是能看出 dependency」，所以是**分層排列**
 * 而不是力導向圖：每個任務放在「最長相依鏈長度」那一層，同層由上往下排。
 * 這樣讀起來就是「左邊做完才輪到右邊」，正好是使用者要問的問題。
 *
 * 用 SVG 而不是 canvas：節點要能被鍵盤 focus、被螢幕閱讀器讀到，而且顏色跟著
 * 既有的狀態色票走。
 */
import { computed } from 'vue';
import { TASK_STATUS_LABELS } from '../../labels';
import type { AiOfficeTask } from '../../types';

const props = defineProps<{ tasks: AiOfficeTask[] }>();

const emit = defineEmits<{ (e: 'select', id: number): void }>();

const NODE_WIDTH = 148;
const NODE_HEIGHT = 44;
const COLUMN_GAP = 56;
const ROW_GAP = 16;

interface Node {
    id: number;
    title: string;
    status: string;
    x: number;
    y: number;
}

/**
 * 每個任務的層數＝最長相依鏈。
 *
 * **必須能容忍資料裡有環**：後端 `TaskDependencyController` 會擋住新增會成環的
 * 相依，但這個元件不該假設拿到的資料一定乾淨（舊資料、手動改過的 DB、之後新增
 * 的寫入路徑）。用「看過就不再往下」的方式收斂，環裡的節點會停在已知的最大層，
 * 圖仍然畫得出來——比整個畫面當掉好。
 */
function levelsOf(tasks: AiOfficeTask[]): Map<number, number> {
    const byId = new Map(tasks.map((task) => [task.id, task]));
    const levels = new Map<number, number>();

    const resolve = (id: number, seen: Set<number>): number => {
        const cached = levels.get(id);
        if (cached !== undefined) return cached;
        if (seen.has(id)) return 0;

        const task = byId.get(id);
        const deps = (task?.dependencies ?? []).filter((depId) => byId.has(depId));

        seen.add(id);
        const level = deps.length === 0
            ? 0
            : Math.max(...deps.map((depId) => resolve(depId, seen))) + 1;
        seen.delete(id);

        levels.set(id, level);

        return level;
    };

    for (const task of tasks) {
        resolve(task.id, new Set());
    }

    return levels;
}

const layout = computed(() => {
    const levels = levelsOf(props.tasks);
    const columns = new Map<number, AiOfficeTask[]>();

    for (const task of props.tasks) {
        const level = levels.get(task.id) ?? 0;
        columns.set(level, [...(columns.get(level) ?? []), task]);
    }

    const nodes: Node[] = [];

    for (const [level, tasksInColumn] of columns) {
        tasksInColumn.forEach((task, index) => {
            nodes.push({
                id: task.id,
                title: task.title,
                status: task.status,
                x: level * (NODE_WIDTH + COLUMN_GAP),
                y: index * (NODE_HEIGHT + ROW_GAP),
            });
        });
    }

    const byId = new Map(nodes.map((node) => [node.id, node]));
    const edges = props.tasks.flatMap((task) =>
        (task.dependencies ?? [])
            .map((depId) => ({ from: byId.get(depId), to: byId.get(task.id) }))
            // 相依指向的任務可能不在這一頁（分頁、或被刪掉）——畫不出來就跳過，
            // 不要畫一條連到 (0,0) 的線。
            .filter((edge): edge is { from: Node; to: Node } => Boolean(edge.from && edge.to)),
    );

    const width = Math.max(...nodes.map((node) => node.x + NODE_WIDTH), NODE_WIDTH);
    const height = Math.max(...nodes.map((node) => node.y + NODE_HEIGHT), NODE_HEIGHT);

    return { nodes, edges, width, height };
});

function statusLabel(status: string): string {
    // 後端之後新增狀態時，這裡不該整個炸掉——查不到就顯示原始值。
    return (TASK_STATUS_LABELS as Record<string, string>)[status] ?? status;
}
</script>

<template>
    <section class="task-graph">
        <h2>相依關係</h2>

        <div v-if="layout.nodes.length" class="scroll">
            <svg
                :viewBox="`-4 -4 ${layout.width + 8} ${layout.height + 8}`"
                :width="layout.width + 8"
                :height="layout.height + 8"
                role="img"
                aria-label="任務相依關係圖"
            >
                <defs>
                    <marker id="task-graph-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
                        <path d="M0,0 L8,4 L0,8 z" fill="currentColor" />
                    </marker>
                </defs>

                <line
                    v-for="(edge, index) in layout.edges"
                    :key="index"
                    class="edge"
                    :x1="edge.from.x + 148"
                    :y1="edge.from.y + 22"
                    :x2="edge.to.x"
                    :y2="edge.to.y + 22"
                    marker-end="url(#task-graph-arrow)"
                />

                <g
                    v-for="node in layout.nodes"
                    :key="node.id"
                    class="node"
                    :data-status="node.status"
                    :data-id="node.id"
                    tabindex="0"
                    role="button"
                    :aria-label="`${node.title}（${statusLabel(node.status)}）`"
                    @click="emit('select', node.id)"
                    @keyup.enter="emit('select', node.id)"
                >
                    <rect :x="node.x" :y="node.y" :width="148" :height="44" rx="6" />
                    <text :x="node.x + 10" :y="node.y + 19">{{ node.title }}</text>
                    <text :x="node.x + 10" :y="node.y + 35" class="status">{{ statusLabel(node.status) }}</text>
                </g>
            </svg>
        </div>
        <p v-else class="hint">還沒有任務。</p>
    </section>
</template>

<style scoped>
/* 相依鏈長了就橫向捲動，不要把節點擠成看不懂的一團。 */
.scroll {
    overflow-x: auto;
    padding-bottom: 0.25rem;
}

.edge {
    stroke: var(--ai-border, #26313d);
    stroke-width: 1.5;
    color: var(--ai-border, #26313d);
}

.node {
    cursor: pointer;
}

.node rect {
    fill: var(--ai-panel, #111820);
    stroke: var(--ai-border, #26313d);
    stroke-width: 1.5;
}

.node text {
    fill: var(--ai-text, #e2e8f0);
    font-size: 12px;
}

.node text.status {
    fill: var(--ai-muted, #8ba0b4);
    font-size: 11px;
}

/* 狀態色跟其他面板同一套，不另外發明一組。 */
.node[data-status='completed'] rect,
.node[data-status='approved'] rect {
    stroke: #38a169;
}

.node[data-status='running'] rect {
    stroke: #3182ce;
}

.node[data-status='waiting_review'] rect {
    stroke: #dd6b20;
}

.node[data-status='failed'] rect,
.node[data-status='rejected'] rect {
    stroke: #e53e3e;
}

.node:focus-visible rect {
    outline: 2px solid #4fd1c5;
}

.hint {
    color: var(--ai-muted);
    font-size: 0.85rem;
}
</style>
