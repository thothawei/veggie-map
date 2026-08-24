<?php

use Illuminate\Support\Facades\Route;

// Vue Router 用 history 模式，所有非 /api、非 /up 的路徑都交給同一個 SPA shell，
// 前端路由自己決定要渲染哪個頁面（見 resources/js/router）。
Route::view('/{any}', 'app')->where('any', '.*');
