<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VeggieMap — 找到適合你的素食餐廳</title>
    <meta name="description" content="素食 × 地圖 × 多條件搜尋，找到適合你的素食友善餐廳。">

    {{-- PWA：讓 iPhone Safari「加入主畫面」後可全螢幕、離線快取地圖殼層 --}}
    {{-- manifest.webmanifest 固定跟著 Vite 的 build.outDir 一起產出，位置是 /build/ 底下 --}}
    <link rel="manifest" href="/build/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/logo.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon-180x180.png">
    <meta name="theme-color" content="#15803d">

    {{-- iOS 不支援 manifest 的 display:standalone，要靠這三個 legacy meta tag 補上 --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="VeggieMap">

    @vite(['resources/css/app.css', 'resources/js/main.ts'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
