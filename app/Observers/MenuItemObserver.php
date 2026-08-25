<?php

namespace App\Observers;

use App\Models\MenuItem;
use App\Services\RestaurantCacheInvalidator;

/**
 * 詳情 cache 內嵌 menu_items。菜單增刪不會觸發 Restaurant saved。
 */
class MenuItemObserver
{
    public function saved(MenuItem $item): void
    {
        RestaurantCacheInvalidator::invalidate($item->restaurant_id);
    }

    public function deleted(MenuItem $item): void
    {
        RestaurantCacheInvalidator::invalidate($item->restaurant_id);
    }
}
