<script setup lang="ts">
import { ref } from 'vue';
import { RISK_LABELS, formatEventTime } from '../../labels';
import type { AiOfficeApproval } from '../../types';

defineProps<{ approvals: AiOfficeApproval[]; canDecide?: boolean; loading?: boolean }>();

const emit = defineEmits<{
    (event: 'decide', payload: { id: number; decision: 'approve' | 'reject'; comment: string | null }): void;
}>();

const comments = ref<Record<number, string>>({});

function decide(approval: AiOfficeApproval, decision: 'approve' | 'reject') {
    emit('decide', {
        id: approval.id,
        decision,
        comment: comments.value[approval.id]?.trim() || null,
    });
    delete comments.value[approval.id];
}
</script>

<template>
    <section class="approval-panel">
        <header>
            <h2>待核准</h2>
            <span class="count" :data-empty="approvals.length === 0">{{ approvals.length }}</span>
        </header>

        <p v-if="loading" class="hint">載入中…</p>
        <p v-else-if="approvals.length === 0" class="hint">目前沒有需要人工核准的動作。</p>

        <ul v-else class="items">
            <li v-for="approval in approvals" :key="approval.id" class="item">
                <div class="row">
                    <span class="action">{{ approval.action }}</span>
                    <span class="risk" :data-risk="approval.risk_level">
                        {{ RISK_LABELS[approval.risk_level] ?? approval.risk_level }}
                    </span>
                    <span class="time">{{ formatEventTime(approval.created_at) }}</span>
                </div>
                <p v-if="approval.reason" class="reason">{{ approval.reason }}</p>
                <p v-if="approval.expires_at" class="expires">逾期時間：{{ formatEventTime(approval.expires_at) }}</p>

                <div v-if="canDecide" class="actions">
                    <input
                        v-model="comments[approval.id]"
                        type="text"
                        placeholder="備註（選填）"
                        :aria-label="`第 ${approval.id} 筆核准的備註`"
                    />
                    <button type="button" class="approve" @click="decide(approval, 'approve')">核准</button>
                    <button type="button" class="reject" @click="decide(approval, 'reject')">拒絕</button>
                </div>
                <p v-else class="hint">只有 admin／manager 可以核准。</p>
            </li>
        </ul>
    </section>
</template>

<style scoped>
header {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
}

h2 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
}

.count {
    color: #f6c344;
    font-weight: 700;
}

.count[data-empty='true'] {
    color: var(--ai-muted);
    font-weight: 400;
}

.items {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.item {
    padding: 0.6rem 0.75rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.5rem;
}

.row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.action {
    font-weight: 600;
}

.risk {
    font-size: 0.75rem;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    border: 1px solid var(--ai-border);
}

.risk[data-risk='high'] {
    color: #f6c344;
}

.risk[data-risk='critical'] {
    color: #f2777a;
}

.time,
.expires,
.hint {
    color: var(--ai-muted);
    font-size: 0.8rem;
}

.reason {
    margin: 0.35rem 0 0;
    font-size: 0.85rem;
}

.actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
    flex-wrap: wrap;
}

.actions input {
    flex: 1 1 10rem;
    padding: 0.35rem 0.5rem;
    background: var(--ai-bg);
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: inherit;
}

.actions button {
    padding: 0.35rem 0.9rem;
    border-radius: 0.375rem;
    border: 1px solid var(--ai-border);
    cursor: pointer;
}

.approve {
    background: #2f855a;
    color: #fff;
}

.reject {
    background: transparent;
    color: #f2777a;
}
</style>
