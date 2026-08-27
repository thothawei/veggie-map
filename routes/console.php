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
/*
| 每個城市錯開 10 分鐘，不要在同一秒一起打 Overpass。
|
| 理由不是我們自己的效能：Overpass 是免費的社群服務，使用政策明確要求節制。
| 五個 bbox 同時送出去，對它是一次尖峰；2026-08-26 手動重跑時東京（最大的 bbox）
| 就連續拿到兩次 HTTP 504。錯開之後單一失敗也不會連帶影響其他城市。
|
| 起點 01:00，之後每個 +10 分鐘。城市數量變多時最後一個仍在 02:00 之前，
| 不會撞到 00:00 那兩個重算工作的長尾。
*/
foreach (array_values(config('services.sync_regions')) as $index => $region) {
    $minute = str_pad((string) (($index * 10) % 60), 2, '0', STR_PAD_LEFT);
    $hour = str_pad((string) (1 + intdiv($index * 10, 60)), 2, '0', STR_PAD_LEFT);

    Schedule::command('restaurants:sync', [
        '--bbox' => $region['bbox'],
        '--diet' => $region['diet'],
    ])->dailyAt("{$hour}:{$minute}");
}

/*
| 永久歇業偵測（restaurants:check-closed）。
|
| 排在 03:00：sync 那批最晚 01:40 開始，跑完才輪到這裡，才不會在同一家店的
| 資料正在被 upsert 的時候去問它還在不在。
|
| 每天只掃一批（config services.business_status.batch_limit），靠 cache 游標
| 輪替——一次掃完一千多家對免費的 Overpass 不禮貌，用 Google Places 則是
| 一千多次計費請求。慢慢輪完一圈是刻意的。
*/
Schedule::command('restaurants:check-closed')->dailyAt('03:00');
