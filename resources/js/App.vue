<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { useFavoritesStore } from '@/stores/favorites';

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
</script>

<template>
    <div class="app-shell">
        <header class="app-header">
            <RouterLink to="/" class="brand">VeggieMap</RouterLink>
            <nav>
                <RouterLink to="/restaurants">餐廳搜尋</RouterLink>
                <!--
                    消費者端不需要登入：瀏覽、搜尋、看詳細資料全部免帳號（2026-08-25 決定）。
                    導覽列因此不再出現收藏／個人資料／登入。管理後台與登出保留——後台審核
                    與 AI Office 子系統仍然需要登入，那些不是消費者功能。路由本身沒有刪除，
                    /login 仍可直接輸入網址進入，否則管理員無從登入。
                -->
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
