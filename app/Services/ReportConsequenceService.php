<?php

namespace App\Services;

use App\Models\DietType;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantReport;
use App\Support\DietCatalog;
use InvalidArgumentException;

/**
 * Admin 核准回報之後對餐廳的連動。動作名稱來自 config/diet.php report_actions，
 * 不要在 Controller 寫 type × kind 的 switch。
 */
class ReportConsequenceService
{
    /**
     * @var list<string>
     */
    public const ACTIONS = [
        'noop',
        'demote_to_friendly',
        'remove_exclusive_codes',
        'clear_menu_items',
        'deactivate',
    ];

    public function apply(RestaurantReport $report): void
    {
        $restaurant = $report->restaurant;
        $restaurant->loadMissing('dietTypes');

        $kind = DietCatalog::venueKindFromCodes($restaurant->dietTypes->pluck('code')->all());
        $action = DietCatalog::reportAction($report->type, $kind);

        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(
                "Unknown report action [{$action}] for type [{$report->type}] kind [{$kind}]. "
                .'Expected '.implode(', ', self::ACTIONS).'.'
            );
        }

        match ($action) {
            'noop' => null,
            'demote_to_friendly' => $this->demoteToFriendly($restaurant),
            'remove_exclusive_codes' => $this->removeExclusiveCodes($restaurant),
            'clear_menu_items' => $this->clearMenuItems($restaurant),
            'deactivate' => $restaurant->update(['status' => 'inactive']),
        };
    }

    private function demoteToFriendly(Restaurant $restaurant): void
    {
        $next = [];

        foreach ($restaurant->dietTypes->pluck('code') as $code) {
            $kind = DietCatalog::kindFor($code);

            if ($kind === 'exclusive') {
                $friendly = DietCatalog::friendlyCounterpart($code);

                if ($friendly !== null) {
                    $next[] = $friendly;
                }

                continue;
            }

            $next[] = $code;
        }

        $this->replaceDietCodes($restaurant, array_values(array_unique($next)));
    }

    private function removeExclusiveCodes(Restaurant $restaurant): void
    {
        $next = [];

        foreach ($restaurant->dietTypes->pluck('code') as $code) {
            if (DietCatalog::kindFor($code) !== 'exclusive') {
                $next[] = $code;
            }
        }

        $this->replaceDietCodes($restaurant, array_values(array_unique($next)));
    }

    /**
     * 逐筆 delete（不是 query delete）才會觸發 MenuItemObserver 清詳情快取；
     * 走 lazyById 而不是 each()／chunk()——offset 分頁一邊刪一邊翻頁會跳過資料，
     * 超過一批（1000 筆）的菜單會留下沒刪掉的殘骸。
     */
    private function clearMenuItems(Restaurant $restaurant): void
    {
        $restaurant->menuItems()->lazyById()->each(fn (MenuItem $item) => $item->delete());
    }

    /**
     * @param  list<string>  $codes
     */
    private function replaceDietCodes(Restaurant $restaurant, array $codes): void
    {
        $ids = DietType::query()->whereIn('code', $codes)->pluck('id', 'code');
        $sync = [];

        foreach ($codes as $code) {
            if ($ids->has($code)) {
                $sync[] = $ids[$code];
            }
        }

        $restaurant->dietTypes()->sync($sync);
        $restaurant->unsetRelation('dietTypes');
        RestaurantCacheInvalidator::invalidate($restaurant->id);
    }
}
