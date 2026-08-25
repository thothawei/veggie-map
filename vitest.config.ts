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
        // 只掃自己的前端原始碼。composer 套件會夾帶自己的 .test.ts（例如
        // vendor/standard-webhooks 就有一支，還 import 了我們沒安裝的 @stablelib/utf8），
        // 沒有限定範圍的話 vitest 會把它撿起來跑，整個前端測試套件因此紅掉——
        // 那不是我們的程式碼，也不該由我們的 CI 負責。
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        // 元件測試需要 DOM；geo.test.ts 那種純函式測試在 jsdom 下一樣跑得動，
        // 不用為了兩種測試分兩套環境。
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
        // 測試跑在固定時區。`formatEventTime()` 用的是 Date 的本機時間 getter
        // （產品行為正確：事件流要顯示看的人當地的時間），但斷言若跟著機器的時區
        // 走，本機（Asia/Taipei）綠、GitHub Actions（UTC）紅——CI 自 2026-08-25
        // Phase 8 起就一直紅在這一條。
        //
        // 釘死時區跟 open_now 那組測試釘死「現在幾點」是同一件事：跟環境有關的
        // 東西不釘住，測試就是「有時綠有時紅」的假保護。用 Asia/Taipei 是因為
        // 這個產品的主要使用者在台灣，斷言裡的時間讀起來也才有意義。
        // 本機要重現 CI：`TZ=UTC npx vitest run`。
        env: { TZ: 'Asia/Taipei' },
    },
});
