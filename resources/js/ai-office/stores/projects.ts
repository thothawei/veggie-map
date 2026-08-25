import { defineStore } from 'pinia';
import { createProject, getProject, listProjects, type NewProject } from '../api/projects';
import type { AiOfficeProject } from '../types';

interface State {
    projects: AiOfficeProject[];
    current: AiOfficeProject | null;
    loading: boolean;
    error: string | null;
}

export const useProjectsStore = defineStore('aiOfficeProjects', {
    state: (): State => ({ projects: [], current: null, loading: false, error: null }),
    getters: {
        /** 統計數字一律從 API 資料算，不寫死（規格第 7／38／74 節）。 */
        countByStatus: (state) => state.projects.reduce<Record<string, number>>((acc, project) => {
            acc[project.status] = (acc[project.status] ?? 0) + 1;

            return acc;
        }, {}),
    },
    actions: {
        async fetchAll() {
            this.loading = true;
            this.error = null;
            try {
                this.projects = await listProjects();
            } catch {
                this.error = '載入專案失敗';
            } finally {
                this.loading = false;
            }
        },
        async fetchOne(id: number) {
            this.loading = true;
            this.error = null;
            try {
                this.current = await getProject(id);
            } catch {
                this.error = '載入專案失敗';
            } finally {
                this.loading = false;
            }
        },
        /**
         * 建立成功後把新專案插在最前面而不是重抓整份清單：規劃是非同步的，
         * 重抓也不會拿到更多資訊，只是多打一次 API。
         */
        async create(payload: NewProject) {
            const project = await createProject(payload);
            this.projects = [project, ...this.projects];

            return project;
        },
    },
});
