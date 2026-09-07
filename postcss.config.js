export default {
    plugins: {
        // Tailwind 4：PostCSS plugin 拆成獨立套件，設定不再吃 tailwind.config.js
        // 的預設路徑（見 resources/css/app.css 開頭的 @import／@theme）。
        // autoprefixer 拿掉：v4 內建 Lightning CSS 的 vendor prefix 處理。
        '@tailwindcss/postcss': {},
    },
};
