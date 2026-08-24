<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 見 docs/todo.md「已知技術債」：這兩支批次指令原本只能手動執行，改成每天排程跑。
// 沒有 queue worker（見 docs/progress.md Phase 6），底層 Job 用 dispatchSync，排程本身
// 只是定時觸發，不會因為排程而多一份佇列依賴。
Schedule::command('restaurants:recalculate-ratings')->daily();
Schedule::command('restaurants:calculate-scores')->daily();

// restaurants:sync 需要 --bbox，這個專案沒有正式決定過要自動涵蓋哪些城市範圍
// （config('services.sync_bboxes') 對應 EXTERNAL_API_SYNC_BBOXES，見 .env.example），
// 留空就不排程——不要自己編一組座標假裝是產品決策。有設定才排，且錯開時間跑在
// rating／score 重算之後，讓新匯入的餐廳當天就能有分數。
foreach (config('services.sync_bboxes') as $bbox) {
    Schedule::command('restaurants:sync', ['--bbox' => $bbox])->dailyAt('01:00');
}
