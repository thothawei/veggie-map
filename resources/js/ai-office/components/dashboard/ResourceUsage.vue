<script setup lang="ts">
import { computed } from 'vue';
import type { ResourceUsageSnapshot } from '../../types';

const props = defineProps<{ snapshot: ResourceUsageSnapshot | null; loading: boolean }>();

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    return `${value.toFixed(1)} ${units[unit]}`;
}

const items = computed(() => {
    const data = props.snapshot;

    if (!data) {
        return [];
    }

    return [
        {
            label: 'Host 負載（1 分鐘）',
            value: data.host_load.available ? String(data.host_load.one) : '無法取得',
        },
        { label: 'PHP 記憶體用量', value: formatBytes(data.php_memory.used_bytes) },
        { label: '待處理佇列工作', value: data.queue.pending_jobs },
        { label: '執行中任務', value: data.tasks.running_tasks },
        { label: '工作中 Agent', value: data.tasks.working_agents },
        {
            label: '沙箱',
            value: data.sandbox.docker_available ? '可用' : '不可用',
        },
    ];
});
</script>

<template>
    <section class="resource-usage" aria-labelledby="resource-usage-heading">
        <div class="header">
            <h2 id="resource-usage-heading">系統資源</h2>
            <!--
              規格第 39 節：拿不到 host CPU/Memory 就用 application-level metrics，
              但 UI 必須標示資料來源，不能假裝是真的 host 監控。這行不是裝飾。
            -->
            <span v-if="snapshot" class="source" role="note">
                應用層量測（非精確 host 監控）
            </span>
        </div>

        <p v-if="loading" aria-busy="true">載入中…</p>
        <p v-else-if="!snapshot" role="alert">系統資源資訊載入失敗。</p>
        <ul v-else class="grid">
            <li v-for="item in items" :key="item.label" class="item">
                <span class="value">{{ item.value }}</span>
                <span class="label">{{ item.label }}</span>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.resource-usage {
    padding: 0.75rem 1rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.5rem;
}

.header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
}

h2 {
    margin: 0;
    font-size: 0.95rem;
}

.source {
    font-size: 0.75rem;
    color: var(--ai-muted);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
    gap: 0.75rem;
    margin: 0.75rem 0 0;
    padding: 0;
    list-style: none;
}

.item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.value {
    font-size: 1.1rem;
    font-weight: 700;
}

.label {
    font-size: 0.75rem;
    color: var(--ai-muted);
}
</style>
