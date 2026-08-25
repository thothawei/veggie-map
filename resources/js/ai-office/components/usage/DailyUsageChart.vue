<script setup lang="ts">
import { computed } from 'vue';
import type { UsageDailyRow } from '../../types';

const props = defineProps<{ rows: UsageDailyRow[] }>();

/**
 * 每日 token 用量長條圖。刻意不引第三方圖表套件：一張長條圖用 div 高度就能畫，
 * 為它多背一個 100KB 的相依不划算，也不用擔心它的預設配色跟這頁的深色主題打架。
 */
const max = computed(() => Math.max(1, ...props.rows.map((row) => row.total_tokens)));

const bars = computed(() => props.rows.map((row) => ({
    ...row,
    // 最小 2% 是為了讓「有用量但很少」的日子看得見；0 的日子後端根本不會回。
    heightPercent: Math.max(2, Math.round((row.total_tokens / max.value) * 100)),
    label: row.day.slice(5),
})));
</script>

<template>
    <div class="chart">
        <p v-if="bars.length === 0" class="empty">這段期間沒有任何 LLM 請求。</p>

        <ol v-else class="bars">
            <li v-for="bar in bars" :key="bar.day">
                <span
                    class="bar"
                    :style="{ height: `${bar.heightPercent}%` }"
                    :title="`${bar.day}：${bar.total_tokens} tokens／$${bar.estimated_cost}`"
                />
                <span class="day">{{ bar.label }}</span>
                <span class="sr-only">{{ bar.day }}：{{ bar.total_tokens }} tokens</span>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.bars {
    display: flex;
    align-items: flex-end;
    gap: 0.25rem;
    height: 8rem;
    margin: 0;
    padding: 0;
    list-style: none;
    overflow-x: auto;
}

.bars li {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    gap: 0.25rem;
    height: 100%;
    min-width: 1.75rem;
}

.bar {
    width: 1.25rem;
    background: #2f855a;
    border-radius: 0.125rem 0.125rem 0 0;
}

.day {
    font-size: 0.65rem;
    color: var(--ai-muted);
    font-variant-numeric: tabular-nums;
}

.empty {
    color: var(--ai-muted);
    font-size: 0.85rem;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
