<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\External\BusinessStatus;
use App\Services\External\BusinessStatusProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 自動把永久歇業的店從地圖上拿掉。
 *
 * 起點是一個很具體的情境：使用者從餐廳詳情頁點「在 Google 地圖開啟」，看到
 * 「永久歇業」——在這之前，這件事只能靠有人回報、再等 admin 核准。
 *
 * 兩個刻意的設計：
 *
 * 1. **下架不是刪除**（status=inactive）。判斷錯了救得回來，reviews／favorites
 *    的外鍵也不會跟著消失。跟回報核准、重複審核的處置一致。
 * 2. **只信明確的歇業訊號**。provider 回 Unknown（查不到、超時、Google 沒收錄）
 *    一律不動手。反過來做的話，外部來源的任何一次閃失都會把還在營業的店抹掉，
 *    而使用者不會回頭來檢查地圖上少了誰。
 */
class CheckClosedRestaurants extends Command
{
    protected $signature = 'restaurants:check-closed
        {--dry-run : 只報告會下架哪些，不寫入}
        {--limit= : 這次最多檢查幾家，預設 config services.business_status.batch_limit}
        {--id=* : 只檢查指定的餐廳 id（用來覆驗單一店家）}';

    protected $description = '查外部來源的營業狀態，把永久歇業的店自動下架';

    public function handle(BusinessStatusProviderInterface $provider): int
    {
        $limit = (int) ($this->option('limit') ?? config('services.business_status.batch_limit', 200));
        $ids = array_map('intval', (array) $this->option('id'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Restaurant::query()
            ->where('status', 'active')
            ->select(['id', 'name', 'source', 'source_id', 'address', 'latitude', 'longitude', 'status']);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            // 游標分批：沒有它的話，每天的排程都會重複檢查 id 最小的那批，
            // 後面的店永遠輪不到。存 cache 而不是加一個 schema 欄位——
            // 它掉了最壞的情況只是從頭再掃一輪，對這個用途夠好。
            $query->where('id', '>', $this->cursor())->orderBy('id')->limit($limit);
        }

        $restaurants = $query->get();

        // 掃到底了就回到開頭，下一次排程從第一家繼續。
        if ($ids === [] && $restaurants->isEmpty() && $this->cursor() > 0) {
            $this->rememberCursor(0);
            $this->info('已經掃到最後一家，游標歸零，下一輪從頭開始。');

            return self::SUCCESS;
        }

        if ($restaurants->isEmpty()) {
            $this->info('沒有需要檢查的餐廳。');

            return self::SUCCESS;
        }

        $this->info("以 [{$provider->name()}] 檢查 {$restaurants->count()} 家…");

        $statuses = $provider->statusFor($restaurants);

        $closed = [];
        $checked = 0;

        foreach ($restaurants as $restaurant) {
            $status = $statuses[$restaurant->id] ?? BusinessStatus::Unknown;

            if ($status === BusinessStatus::Unknown) {
                continue;
            }

            $checked++;

            if (! $status->isClosedPermanently()) {
                continue;
            }

            $closed[] = $restaurant;

            if ($dryRun) {
                continue;
            }

            $restaurant->update(['status' => 'inactive']);

            Log::info('restaurant.auto_deactivated', [
                'restaurant_id' => $restaurant->id,
                'name' => $restaurant->name,
                'provider' => $provider->name(),
                'source' => $restaurant->source,
                'source_id' => $restaurant->source_id,
            ]);
        }

        $this->table(
            ['id', '店名', '處置'],
            array_map(fn (Restaurant $r) => [
                $r->id,
                $r->name,
                $dryRun ? '會下架（dry-run，未寫入）' : '已下架',
            ], $closed),
        );

        // 游標推到這批的最後一家。dry-run 不推進——dry-run 的用途是「先看看
        // 會動到誰」，推進了游標就等於這批被跳過，真的要跑時反而漏掉。
        if ($ids === [] && ! $dryRun) {
            $this->rememberCursor((int) $restaurants->last()->id);
        }

        $this->info(sprintf(
            '查到明確狀態 %d 家，其中永久歇業 %d 家%s。',
            $checked,
            count($closed),
            $dryRun ? '（dry-run）' : '',
        ));

        return self::SUCCESS;
    }

    private const CURSOR_KEY = 'restaurants:closed-check:cursor';

    private function cursor(): int
    {
        return (int) Cache::get(self::CURSOR_KEY, 0);
    }

    private function rememberCursor(int $id): void
    {
        Cache::forever(self::CURSOR_KEY, $id);
    }
}
