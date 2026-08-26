<?php

use Illuminate\Support\Facades\Route;

/*
| API 文件（總 Prompt「最終完成標準」列的 /docs）。
|
| 只有 `docs.enabled` 打開才註冊路由——production 預設關閉（.env 可開）。
| 這不是安全機制（規格本來就是公開的 REST API），而是「production 不要出現
| 一個沒有人維護、只會誤導的頁面」。要在正式站開就把 VEGGIEMAP_DOCS_ENABLED 設 true。
|
| 規格檔直接送 docs/openapi.yaml 本人，不另外複製一份到 public/——複製就會有
| 「文件更新了但網站上還是舊的」這種漂移。
*/
if (config('veggiemap.docs.enabled')) {
    Route::get('/docs', fn () => view('docs.openapi'))->name('docs.openapi');

    Route::get('/docs/openapi.yaml', function () {
        $path = base_path('docs/openapi.yaml');

        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/yaml; charset=utf-8']);
    })->name('docs.openapi.spec');
}

// Vue Router 用 history 模式，所有非 /api、非 /up 的路徑都交給同一個 SPA shell，
// 前端路由自己決定要渲染哪個頁面（見 resources/js/router）。
Route::view('/{any}', 'app')->where('any', '.*');
