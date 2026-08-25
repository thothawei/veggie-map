<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 料理種類 — OSM `cuisine` → 中文標籤
    |--------------------------------------------------------------------------
    |
    | code 就是 OSM cuisine 的值（分號／逗號分隔的其中一段）。對不上的值丟掉，
    | 不拿店名去猜、也不把 vegetarian／vegan 當成菜系——那是 diet_types 的事。
    | FeatureSeeder 會把這份清單 upsert 進 features，同步時掛到餐廳上；
    | GET /features 篩選列仍然只出 Feature::CODES，不會把菜系混進「寵物友善」。
    |
    */

    'types' => [
        ['code' => 'japanese', 'label' => '日式料理'],
        ['code' => 'sushi', 'label' => '壽司'],
        ['code' => 'ramen', 'label' => '拉麵'],
        ['code' => 'soba', 'label' => '蕎麥麵'],
        ['code' => 'udon', 'label' => '烏龍麵'],
        ['code' => 'izakaya', 'label' => '居酒屋'],
        ['code' => 'bento', 'label' => '便當'],
        ['code' => 'thai', 'label' => '泰式料理'],
        ['code' => 'chinese', 'label' => '中式料理'],
        ['code' => 'stir_fry', 'label' => '中式快炒'],
        ['code' => 'taiwanese', 'label' => '台式料理'],
        ['code' => 'cantonese', 'label' => '粵式料理'],
        ['code' => 'sichuan', 'label' => '川菜'],
        ['code' => 'hakka', 'label' => '客家料理'],
        ['code' => 'dim_sum', 'label' => '點心／飲茶'],
        ['code' => 'dumpling', 'label' => '餃子'],
        ['code' => 'hotpot', 'label' => '火鍋'],
        ['code' => 'noodle', 'label' => '麵食'],
        ['code' => 'korean', 'label' => '韓式料理'],
        ['code' => 'vietnamese', 'label' => '越式料理'],
        ['code' => 'indian', 'label' => '印度料理'],
        ['code' => 'curry', 'label' => '咖哩'],
        ['code' => 'italian', 'label' => '義式料理'],
        ['code' => 'pizza', 'label' => '披薩'],
        ['code' => 'french', 'label' => '法式料理'],
        ['code' => 'mexican', 'label' => '墨西哥料理'],
        ['code' => 'american', 'label' => '美式料理'],
        ['code' => 'western', 'label' => '西式料理'],
        ['code' => 'asian', 'label' => '亞洲料理'],
        ['code' => 'mediterranean', 'label' => '地中海料理'],
        ['code' => 'middle_eastern', 'label' => '中東料理'],
        ['code' => 'burger', 'label' => '漢堡'],
        ['code' => 'sandwich', 'label' => '三明治'],
        ['code' => 'salad', 'label' => '沙拉'],
        ['code' => 'buffet', 'label' => '自助餐'],
        ['code' => 'breakfast', 'label' => '早午餐'],
        ['code' => 'cafe', 'label' => '咖啡廳'],
        ['code' => 'bakery', 'label' => '烘焙／麵包'],
        ['code' => 'dessert', 'label' => '甜點'],
        ['code' => 'ice_cream', 'label' => '冰淇淋'],
        ['code' => 'bubble_tea', 'label' => '飲料／手搖'],
        ['code' => 'seafood', 'label' => '海鮮'],
    ],

];
