<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantSlugAlias;
use App\Support\RestaurantSlug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 把既有餐廳的 slug 換成拼音版本。
 *
 * slug 只在 create 時產生，所以拼音上線前匯入的中文店名仍然是 `osm-node-123`。
 * 這支指令是一次性的資料異動：算出現在的規則會給什麼 slug，不一樣就換掉，
 * **舊值寫進 `restaurant_slug_aliases`**，已經分享出去的網址仍然打得開。
 *
 * 預設 dry-run。真的要寫要加 `--force`——這會改動別人手上的網址。
 */
class BackfillRestaurantSlugs extends Command
{
    protected $signature = 'restaurants:backfill-slugs
        {--force : 真的寫入。不加就只報告會改什麼}
        {--limit=15 : 範例列印幾筆}';

    protected $description = '把既有餐廳的 slug 換成拼音，舊 slug 留成轉址別名';

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $stats = ['scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'aliased' => 0];
        $examples = [];

        // 這一輪算出來的 slug 也要參與撞名檢查：兩家「清心蔬食」在同一次回寫裡
        // 都還沒寫進 DB，只查 DB 的話會算出同一個值，然後撞 unique index。
        $taken = [];

        Restaurant::query()
            ->select(['id', 'name', 'slug', 'source', 'source_id'])
            ->orderBy('id')
            ->chunkById(200, function ($restaurants) use ($write, &$stats, &$examples, &$taken) {
                foreach ($restaurants as $restaurant) {
                    $stats['scanned']++;

                    $target = $this->uniqueSlugFor($restaurant, $taken);

                    if ($target === $restaurant->slug) {
                        $stats['unchanged']++;

                        continue;
                    }

                    $stats['changed']++;
                    $examples[] = [$restaurant->name, $restaurant->slug, $target];

                    if ($write) {
                        $stats['aliased'] += $this->rewrite($restaurant, $target);
                    }
                }
            });

        $this->table(
            ['掃描', '會改／已改', '不變', '新增別名'],
            [[$stats['scanned'], $stats['changed'], $stats['unchanged'], $stats['aliased']]],
        );

        $limit = max(0, (int) $this->option('limit'));

        foreach (array_slice($examples, 0, $limit) as [$name, $from, $to]) {
            $this->line("  {$name}：{$from} → {$to}");
        }

        if (! $write) {
            $this->warn('dry-run，沒有寫入任何東西。確認上面的對照沒問題再加 --force。');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, true>  $taken  這一輪已經配出去、但還沒寫進 DB 的 slug
     */
    private function uniqueSlugFor(Restaurant $restaurant, array &$taken): string
    {
        $base = RestaurantSlug::base(
            $restaurant->name,
            $restaurant->source ?? 'restaurant',
            (string) ($restaurant->source_id ?? $restaurant->id),
        );

        $slug = $base;
        $suffix = 1;

        while ($this->isTaken($slug, $restaurant->id, $taken)) {
            $slug = $base.'-'.(++$suffix);
        }

        $taken[$slug] = true;

        return $slug;
    }

    /**
     * @param  array<string, true>  $taken
     */
    private function isTaken(string $slug, int $ownerId, array $taken): bool
    {
        if (isset($taken[$slug])) {
            return true;
        }

        // 自己現在就叫這個名字不算撞名，否則每跑一次都會多一個 -2。
        if (Restaurant::where('slug', $slug)->where('id', '!=', $ownerId)->exists()) {
            return true;
        }

        return RestaurantSlugAlias::where('slug', $slug)->where('restaurant_id', '!=', $ownerId)->exists();
    }

    /** @return int 新增的別名筆數 */
    private function rewrite(Restaurant $restaurant, string $target): int
    {
        return DB::transaction(function () use ($restaurant, $target): int {
            $oldSlug = $restaurant->slug;

            // 先把舊 slug 佔起來，再改 restaurants。順序反過來的話，中間有人查舊
            // 網址就會拿到 404。同一個舊 slug 可能已經在表裡（重跑），用 upsert 語意。
            $alias = RestaurantSlugAlias::firstOrCreate(
                ['slug' => $oldSlug],
                ['restaurant_id' => $restaurant->id],
            );

            $restaurant->slug = $target;
            $restaurant->save();

            // 新 slug 若曾經是這家店的舊別名，留著會讓它同時是正牌與別名，
            // 解析順序雖然先看正牌不會出錯，但那筆已經沒有意義。
            RestaurantSlugAlias::where('restaurant_id', $restaurant->id)
                ->where('slug', $target)
                ->delete();

            return $alias->wasRecentlyCreated ? 1 : 0;
        });
    }
}
