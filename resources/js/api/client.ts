import axios from 'axios';

const client = axios.create({
    baseURL: `${import.meta.env.VITE_API_BASE_URL ?? ''}/api/v1`,
    headers: { Accept: 'application/json' },
});

client.interceptors.request.use((config) => {
    const token = localStorage.getItem('veggiemap_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

/**
 * token 過期（`config('sanctum.expiration')`，2026-09-06 加上）或被撤銷都是 401。
 * 沒有這道攔截器，使用者會停在畫面上看著一個個請求安靜失敗，自己也不知道
 * 該重新登入——之前 token 永不過期，這個情境從來沒真的發生過。
 *
 * 動態 import store／router：這個檔案在它們之前先被載入（store 依賴這個
 * client），頂層 import 會撞循環依賴。
 */
client.interceptors.response.use(
    (response) => response,
    async (error: unknown) => {
        const status = (error as { response?: { status?: number }; config?: { url?: string } })?.response?.status;
        const url = (error as { config?: { url?: string } })?.config?.url ?? '';

        // 登入／註冊的失敗是帳密錯誤（422），不會是 401；排除它們只是避免
        // 萬一 API 行為改變時，攔截器把登入頁自己的錯誤處理搶走。/auth/refresh
        // 與 /auth/logout 的 401 都代表「session 真的失效了」，要走同一套
        // 清掉狀態＋導去登入頁的路徑，不排除。
        if (status === 401 && url !== '/auth/login' && url !== '/auth/register') {
            const [{ useAuthStore }, { default: router }] = await Promise.all([
                import('@/stores/auth'),
                import('@/router'),
            ]);

            useAuthStore().clearSession();

            if (router.currentRoute.value.name !== 'login') {
                void router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
            }
        }

        return Promise.reject(error);
    },
);

export default client;
