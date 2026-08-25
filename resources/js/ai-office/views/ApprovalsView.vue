<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AiOfficeShell from '../components/AiOfficeShell.vue';
import ApprovalPanel from '../components/dashboard/ApprovalPanel.vue';
import { useApprovalsStore } from '../stores/approvals';
import { useAuthStore } from '@/stores/auth';
import { extractApiErrorMessage } from '@/lib/apiError';

const auth = useAuthStore();
const approvals = useApprovalsStore();
const decideError = ref<string | null>(null);

const canApprove = computed(() => ['admin', 'manager'].includes(auth.user?.role ?? ''));

async function decide(payload: { id: number; decision: 'approve' | 'reject'; comment: string | null }) {
    decideError.value = null;
    try {
        await approvals.decide(payload.id, payload.decision, payload.comment ?? undefined);
    } catch (error: unknown) {
        // 409 代表這筆已經被別人處理掉或過期了，重抓一次比留著錯的清單好。
        decideError.value = extractApiErrorMessage(error, '處理失敗');
        await approvals.fetchPending();
    }
}

onMounted(() => void approvals.fetchPending());
</script>

<template>
    <AiOfficeShell title="待核准動作">
        <p v-if="approvals.error" class="error" role="alert">{{ approvals.error }}</p>
        <p v-if="decideError" class="error" role="alert">{{ decideError }}</p>

        <ApprovalPanel
            class="panel"
            :approvals="approvals.approvals"
            :loading="approvals.loading"
            :can-decide="canApprove"
            @decide="decide"
        />
    </AiOfficeShell>
</template>

<style scoped>
.error {
    color: #f2777a;
    font-size: 0.85rem;
}
</style>
