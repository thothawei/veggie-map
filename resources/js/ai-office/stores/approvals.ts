import { defineStore } from 'pinia';
import { approve, listApprovals, reject } from '../api/approvals';
import type { AiOfficeApproval } from '../types';

interface State {
    approvals: AiOfficeApproval[];
    loading: boolean;
    error: string | null;
}

export const useApprovalsStore = defineStore('aiOfficeApprovals', {
    state: (): State => ({ approvals: [], loading: false, error: null }),
    getters: {
        pendingCount: (state) => state.approvals.filter((item) => item.status === 'pending').length,
    },
    actions: {
        async fetchPending() {
            this.loading = true;
            this.error = null;
            try {
                this.approvals = await listApprovals('pending');
            } catch {
                this.error = '載入待核准項目失敗';
            } finally {
                this.loading = false;
            }
        },
        /**
         * 核准／拒絕後把該筆從清單移除：這份清單的語意是「還待處理的」，
         * 留著一個狀態已變的項目只會讓人再按一次然後拿到 409。
         */
        async decide(id: number, decision: 'approve' | 'reject', comment?: string) {
            const result = decision === 'approve' ? await approve(id, comment) : await reject(id, comment);
            this.approvals = this.approvals.filter((item) => item.id !== id);

            return result;
        },
    },
});
