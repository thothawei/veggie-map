import { defineStore } from 'pinia';
import client from '@/api/client';
import type { ApiSuccess, User } from '@/types';

interface AuthState {
    user: User | null;
    token: string | null;
    /** token 何時過期，`null` 代表永不過期（`config('sanctum.expiration')` 沒設）。 */
    expiresAt: string | null;
}

export const useAuthStore = defineStore('auth', {
    state: (): AuthState => ({
        user: null,
        token: localStorage.getItem('veggiemap_token'),
        expiresAt: localStorage.getItem('veggiemap_token_expires_at'),
    }),
    getters: {
        isAuthenticated: (state) => state.token !== null,
        isAdmin: (state) => state.user?.role === 'admin',
        // AI Office 的入口條件跟後端 EnsureAiOfficeRole 一致：一般消費者 `user`
        // 註冊過也看不到。user 還沒載入時回 false——寧可少顯示一個連結，
        // 也不要先顯示再閃掉。
        canAccessAiOffice: (state) => ['admin', 'manager', 'developer', 'viewer']
            .includes(state.user?.role ?? ''),
    },
    actions: {
        async login(email: string, password: string) {
            const response = await client.post<ApiSuccess<{ token: string; expires_at: string | null; user: User }>>('/auth/login', {
                email,
                password,
            });
            this.setToken(response.data.data.token, response.data.data.expires_at);
            this.user = response.data.data.user;
        },
        async register(name: string, email: string, password: string, passwordConfirmation: string) {
            const response = await client.post<ApiSuccess<{ token: string; expires_at: string | null; user: User }>>('/auth/register', {
                name,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            this.setToken(response.data.data.token, response.data.data.expires_at);
            this.user = response.data.data.user;
        },
        async logout() {
            try {
                await client.post('/auth/logout');
            } finally {
                this.clearSession();
            }
        },
        /**
         * 在過期前換一張新 token，讓活躍使用者不會被 `config('sanctum.expiration')`
         * 的全域上限硬性登出。呼叫端（`App.vue` 的排程）要自己接住失敗——
         * 失敗代表目前這張 token 已經失效了，不是網路問題可以重試的那種錯誤。
         */
        async refresh() {
            const response = await client.post<ApiSuccess<{ token: string; expires_at: string | null }>>('/auth/refresh');
            this.setToken(response.data.data.token, response.data.data.expires_at);
        },
        async fetchCurrentUser() {
            if (!this.token) {
                return;
            }
            const response = await client.get<ApiSuccess<User>>('/me');
            this.user = response.data.data;
        },
        setToken(token: string | null, expiresAt: string | null = null) {
            this.token = token;
            this.expiresAt = expiresAt;

            if (token) {
                localStorage.setItem('veggiemap_token', token);
            } else {
                localStorage.removeItem('veggiemap_token');
            }

            if (expiresAt) {
                localStorage.setItem('veggiemap_token_expires_at', expiresAt);
            } else {
                localStorage.removeItem('veggiemap_token_expires_at');
            }
        },
        /**
         * 只清本機狀態，不打 `/auth/logout`——給「token 已經失效」的情境用
         * （401 攔截器、refresh 失敗），這時候 token 早就不能用了，打登出
         * API 只會再拿到一次 401，沒有意義。
         */
        clearSession() {
            this.setToken(null, null);
            this.user = null;
        },
    },
});
