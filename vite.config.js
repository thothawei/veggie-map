import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.ts'],
            refresh: true,
        }),
        vue(),
        VitePWA({
            // sw.js 要落在網站根目錄（public/）才能讓 scope 涵蓋整個網站，不能跟著
            // JS/CSS 一起進 laravel-vite-plugin 的 public/build/。manifest.webmanifest
            // 這顆不受 outDir 控制、固定跟著 Vite 的 build.outDir 走（見 app.blade.php 的連結），
            // 所以 icons 底下一律用「/」開頭的絕對路徑，才不會被誤解成相對 /build/ 底下找檔案。
            outDir: 'public',
            registerType: 'autoUpdate',
            injectRegister: false, // 改由 resources/js/main.ts 手動呼叫 registerSW，因為入口是 Blade 而非 index.html
            includeAssets: ['favicon.ico', 'logo.svg', 'apple-touch-icon-180x180.png'],
            manifest: {
                name: 'VeggieMap — 素食餐廳地圖',
                short_name: 'VeggieMap',
                description: '素食 × 地圖 × 多條件搜尋，找到適合你的素食友善餐廳。',
                lang: 'zh-Hant',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                theme_color: '#15803d',
                background_color: '#ffffff',
                icons: [
                    { src: '/pwa-64x64.png', sizes: '64x64', type: 'image/png' },
                    { src: '/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
                    {
                        src: '/maskable-icon-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },
            workbox: {
                // 只快取自己的 build 產物；API 資料一律走網路（不 cache-first），避免顯示過期的餐廳資訊。
                globDirectory: 'public',
                globPatterns: ['build/**/*.{js,css}', '*.{png,ico,svg}'],
                navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//],
            },
            devOptions: {
                enabled: false,
            },
        }),
    ],
    server: {
        port: 5173,
    },
});
