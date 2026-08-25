<script setup lang="ts">
import { computed } from 'vue';
import { AGENT_STATUS_LABELS } from '../../labels';
import type { AgentStatus } from '../../types';

const props = defineProps<{ name: string; role: string; status: AgentStatus }>();

/**
 * 純 SVG 方塊拼的像素小人（規格第 45 節：CSS + SVG，不用點陣圖）。
 * `shape-rendering="crispEdges"` 是重點——沒有它，縮放時邊緣會被抗鋸齒糊掉，
 * 就不是像素風而是一團模糊的方塊。
 */

/** 角色配色。對不上的角色用中性灰，不要隨機給色——同一個角色每次進來要長一樣。 */
const ROLE_COLORS: Record<string, string> = {
    ceo: '#f6c344',
    backend: '#6aa9f0',
    frontend: '#7fd18f',
    qa: '#d08cf0',
    design: '#f0908c',
    devops: '#61c9c0',
    automation: '#c0c86a',
};

const shirt = computed(() => ROLE_COLORS[props.role] ?? '#8a97a6');

/** 等待核准時舉手，一眼就看得出「這個人卡住了、在等你」。 */
const raisingHand = computed(() => props.status === 'waiting_review');

const label = computed(() => `${props.name}（${props.role}）：${AGENT_STATUS_LABELS[props.status] ?? props.status}`);
</script>

<template>
    <svg
        class="character"
        :data-status="status"
        viewBox="0 0 16 20"
        width="32"
        height="40"
        shape-rendering="crispEdges"
        role="img"
        :aria-label="label"
    >
        <!-- 頭髮／頭 -->
        <rect x="5" y="1" width="6" height="2" fill="#2b2f36" />
        <rect x="4" y="3" width="8" height="5" fill="#e8c39e" />
        <rect x="4" y="3" width="8" height="1" fill="#2b2f36" />
        <rect x="6" y="5" width="1" height="1" class="eye" fill="#2b2f36" />
        <rect x="9" y="5" width="1" height="1" class="eye" fill="#2b2f36" />

        <!-- 身體 -->
        <rect x="4" y="8" width="8" height="7" :fill="shirt" />
        <rect x="6" y="8" width="4" height="2" fill="#111820" opacity="0.25" />

        <!-- 手臂：工作中會上下敲鍵盤，等待核准時右手舉起來 -->
        <rect class="arm left" x="2" y="9" width="2" height="5" :fill="shirt" />
        <rect
            class="arm right"
            :class="{ raised: raisingHand }"
            x="12"
            :y="raisingHand ? 4 : 9"
            width="2"
            :height="raisingHand ? 6 : 5"
            :fill="shirt"
        />

        <!-- 腿 -->
        <rect x="5" y="15" width="2" height="4" fill="#39414d" />
        <rect x="9" y="15" width="2" height="4" fill="#39414d" />
    </svg>
</template>

<style scoped>
.character {
    display: block;
}

/* 工作中：手臂小幅上下，像在打字。 */
.character[data-status='working'] .arm {
    animation: typing 0.6s steps(2, end) infinite alternate;
}

.character[data-status='working'] .arm.right {
    animation-delay: 0.3s;
}

/* 錯誤：整個人偏紅並停住不動，跟「正在忙」明確區分開。 */
.character[data-status='error'] {
    filter: sepia(1) saturate(4) hue-rotate(-40deg);
}

/* 離線：灰階＋半透明，不是換一張圖。 */
.character[data-status='offline'] {
    filter: grayscale(1);
    opacity: 0.45;
}

.character[data-status='idle'] .eye {
    opacity: 0.55;
}

@keyframes typing {
    to {
        transform: translateY(1px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .character[data-status='working'] .arm {
        animation: none;
    }
}
</style>
