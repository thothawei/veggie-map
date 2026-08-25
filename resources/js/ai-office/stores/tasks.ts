import { defineStore } from 'pinia';
import { createTask, getTask, listTasks, updateTask, type NewTask } from '../api/tasks';
import { TASK_STATUSES, type AiOfficeTask, type TaskStatus } from '../types';

interface State {
    tasks: AiOfficeTask[];
    selected: AiOfficeTask | null;
    loading: boolean;
    error: string | null;
}

export const useTasksStore = defineStore('aiOfficeTasks', {
    state: (): State => ({ tasks: [], selected: null, loading: false, error: null }),
    getters: {
        /** 看板欄位。空欄位也要在（不然任務跑到那一格時整個版面會跳動）。 */
        byStatus: (state) => TASK_STATUSES.reduce<Record<TaskStatus, AiOfficeTask[]>>((acc, status) => {
            acc[status] = state.tasks.filter((task) => task.status === status);

            return acc;
        }, {} as Record<TaskStatus, AiOfficeTask[]>),
    },
    actions: {
        async fetchForProject(projectId: number) {
            this.loading = true;
            this.error = null;
            try {
                this.tasks = await listTasks(projectId);
            } catch {
                this.error = '載入任務失敗';
            } finally {
                this.loading = false;
            }
        },
        async select(id: number) {
            this.selected = await getTask(id);
        },
        clearSelection() {
            this.selected = null;
        },
        async create(projectId: number, payload: NewTask) {
            const task = await createTask(projectId, payload);
            this.tasks = [...this.tasks, task];

            return task;
        },
        async changeStatus(task: AiOfficeTask, status: TaskStatus) {
            const updated = await updateTask(task.id, { status });
            this.replace(updated);

            return updated;
        },
        replace(task: AiOfficeTask) {
            this.tasks = this.tasks.map((item) => (item.id === task.id ? task : item));

            if (this.selected?.id === task.id) {
                this.selected = task;
            }
        },
    },
});
