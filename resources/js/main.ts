import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuthStore } from './stores/auth';

const app = createApp(App);

app.use(createPinia());

const auth = useAuthStore();

// 帶著舊 token 直接刷新／硬導航到 /admin 這種需要 auth.user 的頁面時，router guard
// 會在 fetchCurrentUser() resolve 之前就先跑完並把人導回首頁——瀏覽器實測真的重現過
// 這個問題（見 docs/progress.md Phase 9）。一開始只把 app.mount() 延到 fetchCurrentUser
// resolve 之後還是沒用，因為 Vue Router 4 的初始導航是在 app.use(router) 當下就觸發，
// 不是等 app.mount()；真正有效的做法是連 app.use(router) 本身都要延後，
// 確保 router guard 第一次跑的時候 auth.user 已經有值。
async function bootstrap() {
    if (auth.isAuthenticated) {
        await auth.fetchCurrentUser().catch(() => auth.setToken(null));
    }

    app.use(router);
    app.mount('#app');
}

bootstrap();
