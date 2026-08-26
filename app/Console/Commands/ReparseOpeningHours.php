<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\OpeningHoursService;
use App\Support\OpeningHours;
use Illuminate\Console\Command;

/**
 * 用**已經存下來的** `opening_hours` 原始字串重新解析成時段列。
 *
 * 存原始字串的目的就在這裡：解析器改進之後（例如 2026-08-26 補上「逗號是附加
 * 規則」），不必重打 Overpass 也能讓既有資料跟上。重打一次全台灣不但慢，還是
 * 對別人的免費服務多餘的負擔。
 */
class ReparseOpeningHours extends Command
{
    protected $signature = 'restaurants:reparse-opening-hours
        {--dry-run : 只報告會變成什麼，不寫入}';

    protected $description = '用既有的 opening_hours 字串重新產生時段列（解析器改版後用）';

    public function handle(OpeningHoursService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['parsed' => 0, 'unparsable' => 0, 'rows' => 0];
        $failures = [];

        // chunkById 而不是 get()：這張表會長大，一次撈進記憶體是總 Prompt 第
        // 二十一節明講要避免的做法。
        Restaurant::query()
            ->whereNotNull('opening_hours')
            ->where('opening_hours', '!=', '')
            ->chunkById(200, function ($restaurants) use ($service, $dryRun, &$stats, &$failures) {
                foreach ($restaurants as $restaurant) {
                    $rows = OpeningHours::parse($restaurant->opening_hours);

                    if ($rows === null) {
                        $stats['unparsable']++;
                        $failures[] = "{$restaurant->name} => {$restaurant->opening_hours}";

                        continue;
                    }

                    $stats['parsed']++;
                    $stats['rows'] += $dryRun ? count($rows) : $service->sync($restaurant);
                }
            });

        $this->table(
            ['解析成功', '無法解析', '時段列數'],
            [[$stats['parsed'], $stats['unparsable'], $stats['rows']]],
        );

        // 解析不了的一定要列出來：那是「還有哪些 OSM 寫法沒支援」的唯一線索，
        // 只報一個數字的話下次沒人知道要從哪裡改。
        foreach ($failures as $failure) {
            $this->line("  無法解析：{$failure}");
        }

        if ($dryRun) {
            $this->comment('--dry-run：沒有寫入任何資料。');
        }

        return self::SUCCESS;
    }
}
