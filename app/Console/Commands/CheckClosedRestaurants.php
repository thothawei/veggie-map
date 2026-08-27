<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantClosureSignal;
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
        {--id=* : 只檢查指定的餐廳 id（用來覆驗單一店家）}
        {--bbox= : 只檢查這個範圍內的店，格式 "minLat,minLng,maxLat,maxLng"（例如先跑東京）}';

    protected $description = '查外部來源的營業狀態，把永久歇業的店自動下架';

    public function handle(BusinessStatusProviderInterface $provider): int
    {
        $limit = (int) ($this->option('limit') ?? config('services.business_status.batch_limit', 200));
        $ids = array_map('intval', (array) $this->option('id'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Restaurant::query()
            ->where('status', 'active')
            ->select(['id', 'name', 'source', 'source_id', 'address', 'latitude', 'longitude', 'status']);

        $bbox = $this->option('bbox');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } elseif (is_string($bbox) && $bbox !== '') {
            // 指定範圍時不套游標也不套 limit：使用者要的是「把這個區域跑完」，
            // 中途停在游標上會讓人以為跑完了。
            [$minLat, $minLng, $maxLat, $maxLng] = $this->parseBbox($bbox);

            $query->whereBetween('latitude', [$minLat, $maxLat])
                ->whereBetween('longitude', [$minLng, $maxLng]);
        } else {
            // 游標分批：沒有它的話，每天的排程都會重複檢查 id 最小的那批，
            // 後面的店永遠輪不到。存 cache 而不是加一個 schema 欄位——
            // 它掉了最壞的情況只是從頭再掃一輪，對這個用途夠好。
            $query->where('id', '>', $this->cursor())->orderBy('id')->limit($limit);
        }

        $restaurants = $query->get();

        // 掃到底了就回到開頭，下一次排程從第一家繼續。
        if ($ids === [] && $bbox === null && $restaurants->isEmpty() && $this->cursor() > 0) {
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
        $flagged = [];
        $checked = 0;

        foreach ($restaurants as $restaurant) {
            $status = $statuses[$restaurant->id] ?? BusinessStatus::Unknown;

            if ($status === BusinessStatus::Unknown) {
                continue;
            }

            $checked++;

            // 節點不見了：只留一個訊號給人看，不自動下架（見 BusinessStatus::Missing）。
            if ($status === BusinessStatus::Missing) {
                $flagged[] = $restaurant;

                if (! $dryRun) {
                    $this->recordSignal($restaurant, 'osm_node_missing', [
                        'source_id' => $restaurant->source_id,
                        'provider' => $provider->name(),
                    ]);
                }

                continue;
            }

            if (! $status->isClosedPermanently()) {
                // 店還在。之前記過的未審核訊號就是誤報，收掉——留著會讓 Admin
                // 一直看到一份早就不成立的待辦。
                if (! $dryRun) {
                    $this->dismissStaleSignals($restaurant);
                }

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

        $rows = [
            ...array_map(fn (Restaurant $r) => [
                $r->id, $r->name, $dryRun ? '會下架（dry-run）' : '已下架',
            ], $closed),
            ...array_map(fn (Restaurant $r) => [
                $r->id, $r->name, $dryRun ? '會標記待審（dry-run）' : '已標記待審',
            ], $flagged),
        ];

        if ($rows !== []) {
            $this->table(['id', '店名', '處置'], $rows);
        }

        // 游標推到這批的最後一家。dry-run 不推進——dry-run 的用途是「先看看
        // 會動到誰」，推進了游標就等於這批被跳過，真的要跑時反而漏掉。
        // 指定 id 或 bbox 時不動游標：那是臨時的補跑，不該影響每日排程的進度。
        if ($ids === [] && $bbox === null && ! $dryRun) {
            $this->rememberCursor((int) $restaurants->last()->id);
        }

        $this->info(sprintf(
            '查到明確狀態 %d 家：永久歇業 %d 家（已下架）、疑似歇業 %d 家（待 Admin 審核）%s。',
            $checked,
            count($closed),
            count($flagged),
            $dryRun ? '（dry-run，未寫入）' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * 同一家店的同一種訊號只留一筆（表上有 unique）。重複偵測到就更新時間，
     * 不要每天長一筆——排程跑三個月會讓待審清單被同一家店洗版。
     *
     * 已經被審核過的訊號不要復活：Admin 判定「誤報」之後，下一次排程又把
     * resolution 清成 null 的話，那個判斷等於白做，同一筆會永遠回來。
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordSignal(Restaurant $restaurant, string $signal, array $metadata): void
    {
        $existing = RestaurantClosureSignal::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('signal', $signal)
            ->first();

        if ($existing !== null) {
            if ($existing->resolution === null) {
                $existing->update(['detected_at' => now(), 'metadata' => $metadata]);
            }

            return;
        }

        RestaurantClosureSignal::create([
            'restaurant_id' => $restaurant->id,
            'signal' => $signal,
            'metadata' => $metadata,
            'detected_at' => now(),
        ]);
    }

    /** 店確認還在營業，把還沒審的訊號自動收掉。 */
    private function dismissStaleSignals(Restaurant $restaurant): void
    {
        RestaurantClosureSignal::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('resolution')
            ->update([
                'resolution' => 'dismissed',
                'reviewed_at' => now(),
            ]);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function parseBbox(string $bbox): array
    {
        $parts = array_map('trim', explode(',', $bbox));

        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            // 格式錯了就停下來。靜默當成「沒有 bbox」會變成掃全表——
            // 使用者以為只跑了東京，實際上動到了所有城市。
            throw new \InvalidArgumentException(
                'bbox 格式錯誤，需要 "minLat,minLng,maxLat,maxLng" 四個數字。'
            );
        }

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2], (float) $parts[3]];
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
