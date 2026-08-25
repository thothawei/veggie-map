import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

// Vitest 重用 vite.config.js 的話會連 laravel-vite-plugin 一起吃到，該 plugin 在
// CI 環境會直接擋掉（見 node_modules/laravel-vite-plugin：「You should not run the
// Vite HMR server in CI environments」）——單元測試根本不需要 Laravel 的資產管線，
// 給 Vitest 一份獨立、不含 laravel() 的設定，兩邊互不干擾。
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [vue()],
    test: {
        // 元件測試需要 DOM；geo.test.ts 那種純函式測試在 jsdom 下一樣跑得動，
        // 不用為了兩種測試分兩套環境。
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
    },
});
