<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Support\OpeningHours;
use Illuminate\Support\Facades\DB;

class OpeningHoursService
{
    /**
     * 把餐廳的 `opening_hours` 原始字串解析成可查詢的時段列。
     *
     * 覆寫而不是累加：來源字串是這家店營業時間的完整敘述，重跑同步時舊列必須整批
     * 換掉，否則店家改成週日公休之後，舊的週日時段會永遠留著，`open_now` 就會把
     * 打烊的店標成營業中。解析失敗（子集外的語法）時同樣清空——「沒有可信資料」
     * 要表現成查不到時段，不是留著上一版的舊資料。
     *
     * @return int 寫入的時段列數
     */
    public function sync(Restaurant $restaurant): int
    {
        $rows = OpeningHours::parse($restaurant->opening_hours);

        return DB::transaction(function () use ($restaurant, $rows) {
            RestaurantOpeningHour::where('restaurant_id', $restaurant->id)->delete();

            if ($rows === null || $rows === []) {
                return 0;
            }

            $now = now();

            RestaurantOpeningHour::insert(array_map(fn (array $row) => [
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $row['day'],
                'opens_at' => $row['opens_at'],
                'closes_at' => $row['closes_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows));

            return count($rows);
        });
    }
}
