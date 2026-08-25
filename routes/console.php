<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 見 docs/todo.md「已知技術債」：這兩支批次指令原本只能手動執行，改成每天排程跑。
// 底層 Job 走 dispatch() 進 Redis 佇列，由 Horizon worker 消化（見 docs/progress.md
// Horizon 那則），排程本身只負責定時觸發。
Schedule::command('restaurants:recalculate-ratings')->daily();
Schedule::command('restaurants:calculate-scores')->daily();

// 涵蓋範圍與收錄規則由 config('services.sync_regions') 決定（對應 EXTERNAL_API_SYNC_BBOXES，
// 見 .env.example）。規則名稱必須是 config/diet.php sync_modes 的 key。留空則完全不排程。
// 錯開時間跑在 rating／score 重算之後，讓新匯入的餐廳當天就有分數。
foreach (config('services.sync_regions') as $region) {
    Schedule::command('restaurants:sync', [
        '--bbox' => $region['bbox'],
        '--diet' => $region['diet'],
    ])->dailyAt('01:00');
}
