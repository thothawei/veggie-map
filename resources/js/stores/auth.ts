import { defineStore } from 'pinia';
import client from '@/api/client';
import type { ApiSuccess, User } from '@/types';

interface AuthState {
    user: User | null;
    token: string | null;
}

export const useAuthStore = defineStore('auth', {
    state: (): AuthState => ({
        user: null,
        token: localStorage.getItem('veggiemap_token'),
    }),
    getters: {
        isAuthenticated: (state) => state.token !== null,
        isAdmin: (state) => state.user?.role === 'admin',
    },
    actions: {
        async login(email: string, password: string) {
            const response = await client.post<ApiSuccess<{ token: string; user: User }>>('/auth/login', {
                email,
                password,
            });
            this.setToken(response.data.data.token);
            this.user = response.data.data.user;
        },
        async register(name: string, email: string, password: string, passwordConfirmation: string) {
            const response = await client.post<ApiSuccess<{ token: string; user: User }>>('/auth/register', {
                name,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            this.setToken(response.data.data.token);
            this.user = response.data.data.user;
        },
        async logout() {
            try {
                await client.post('/auth/logout');
            } finally {
                this.setToken(null);
                this.user = null;
            }
        },
        async fetchCurrentUser() {
            if (!this.token) {
                return;
            }
            const response = await client.get<ApiSuccess<User>>('/me');
            this.user = response.data.data;
        },
        setToken(token: string | null) {
            this.token = token;
            if (token) {
                localStorage.setItem('veggiemap_token', token);
            } else {
                localStorage.removeItem('veggiemap_token');
            }
        },
    },
});
