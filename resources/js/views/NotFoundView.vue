<script setup lang="ts">
/**
 * Vue Router 沒有 catch-all 路由時，打錯的網址會渲染出**一片空白**——只有導覽列，
 * 中間什麼都沒有（2026-08-26 瀏覽器實測）。使用者看不出是打錯了還是網站壞了。
 *
 * 後端的 `/{any}` 一律回同一個 SPA shell，所以這件事只能在前端處理。
 */
import { RouterLink } from 'vue-router';
</script>

<template>
    <div class="not-found">
        <h1>找不到這個頁面</h1>
        <p>網址可能打錯了，或這個頁面已經不在了。</p>

        <p class="links">
            <RouterLink :to="{ name: 'home' }">回地圖</RouterLink>
            <RouterLink :to="{ name: 'restaurants' }">餐廳搜尋</RouterLink>
        </p>
    </div>
</template>

<style scoped>
.not-found {
    max-width: 480px;
    margin: 4rem auto;
    padding: 0 1rem;
    text-align: center;
    color: #2d3748;
}

.not-found h1 {
    font-size: 1.4rem;
    margin-bottom: 0.5rem;
}

.links {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1.5rem;
}

.links a {
    color: #2f855a;
}
</style>
