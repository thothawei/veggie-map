<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

async function handleLogout() {
    await auth.logout();
}
</script>

<template>
    <div class="app-shell">
        <header class="app-header">
            <RouterLink to="/" class="brand">VeggieMap</RouterLink>
            <nav>
                <RouterLink to="/restaurants">餐廳搜尋</RouterLink>
                <RouterLink v-if="auth.isAuthenticated" to="/favorites">收藏</RouterLink>
                <RouterLink v-if="auth.isAuthenticated" to="/profile">個人資料</RouterLink>
                <RouterLink v-if="auth.isAdmin" to="/admin">管理後台</RouterLink>
                <RouterLink v-if="!auth.isAuthenticated" to="/login">登入</RouterLink>
                <button v-else type="button" class="link-button" @click="handleLogout">登出</button>
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
