<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useFavoritesStore } from '@/stores/favorites';
import { shouldRefreshToken } from '@/lib/tokenRefresh';

const router = useRouter();
const auth = useAuthStore();
const favorites = useFavoritesStore();

async function handleLogout() {
    await auth.logout();
    favorites.reset();

    if (router.currentRoute.value.meta.requiresAuth) {
        await router.push({ name: 'home' });
    }
}

/**
 * `config('sanctum.expiration')`（2026-09-06 加上）讓 token 會過期。與其等
 * 過期後被攔截器踢回登入頁，活躍的分頁每 5 分鐘檢查一次「快到期了嗎」，
 * 快到的話提前換一張——使用者感覺不到，session 卻不會中斷。
 *
 * refresh 失敗（代表這張 token 早就不能用了）不用自己處理登出：`api/client.ts`
 * 的 401 攔截器已經會清狀態並導去登入頁，這裡只要吞掉錯誤、不讓它變成
 * 一個沒人接的 unhandled rejection。
 */
let refreshTimer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    refreshTimer = setInterval(() => {
        if (auth.isAuthenticated && shouldRefreshToken(auth.expiresAt)) {
            auth.refresh().catch(() => {});
        }
    }, 5 * 60_000);
});

onBeforeUnmount(() => {
    clearInterval(refreshTimer);
});
</script>

<template>
    <div class="app-shell">
        <header class="app-header">
            <RouterLink to="/" class="brand">VeggieMap</RouterLink>
            <nav>
                <RouterLink to="/restaurants">餐廳搜尋</RouterLink>
                <!-- 消費者端是公開地圖：不需要帳號、收藏、評論。後台與 AI Office 仍走 /login。 -->
                <RouterLink v-if="auth.canAccessAiOffice" to="/ai-office">AI Office</RouterLink>
                <RouterLink v-if="auth.isAdmin" to="/admin">管理後台</RouterLink>
                <button v-if="auth.isAuthenticated" type="button" class="link-button" @click="handleLogout">登出</button>
            </nav>
        </header>
        <main>
            <RouterView />
        </main>
    </div>
</template>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'PingFang TC', 'Microsoft JhengHei', sans-serif;
    background: #fff;
    color: #1f2933;
}

#app {
    max-width: 100%;
    overflow-x: hidden;
}

.app-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: #2f855a;
    color: #fff;
}

.app-header .brand {
    font-size: 1.25rem;
    font-weight: 700;
    color: #fff;
    text-decoration: none;
    white-space: nowrap;
}

.app-header nav {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    align-items: center;
    justify-content: flex-end;
}

.app-header nav a,
.app-header nav .link-button {
    color: #fff;
    text-decoration: none;
    font-size: 0.9rem;
    white-space: nowrap;
}

.app-header nav a.router-link-active {
    font-weight: 700;
    text-decoration: underline;
}

.link-button {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    font: inherit;
}
</style>
