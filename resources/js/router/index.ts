import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: '/restaurants', name: 'restaurants', component: () => import('@/views/RestaurantListView.vue') },
        {
            // 後端 GET /restaurants/{id} 目前是 id-based route model binding（見
            // docs/api.md），沒有 slug 查詢支援，前端路徑也用 id，不假裝有 slug 查詢能力。
            path: '/restaurants/:id',
            name: 'restaurant-detail',
            component: () => import('@/views/RestaurantDetailView.vue'),
            props: true,
        },
        { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue') },
        {
            path: '/admin',
            name: 'admin',
            component: () => import('@/views/AdminView.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
    ],
});

router.beforeEach((to) => {
    const auth = useAuthStore();

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.requiresAdmin && !auth.isAdmin) {
        return { name: 'home' };
    }

    return true;
});

export default router;
