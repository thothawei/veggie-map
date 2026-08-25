<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Diet types — 單一真相來源
    |--------------------------------------------------------------------------
    |
    | Seeder、GET /diets、OSM 映射、venue_scope 篩選都讀這裡，不要在 PHP／Vue
    | 再寫一份 code 清單。kind=exclusive 是整間素／全素；kind=friendly 是葷素
    | 都有、菜單有無肉選項。osm_tag / osm_values 決定 Overpass 節點怎麼對到 code；
    | 沒有 osm_tag 的類型只給手動／未來菜單用，同步不會寫入。
    |
    */

    'types' => [
        [
            'code' => 'vegan',
            'label' => '全素（Vegan）',
            'kind' => 'exclusive',
            'group_label' => '純素食店',
            'osm_tag' => 'diet:vegan',
            'osm_values' => ['only'],
        ],
        [
            'code' => 'vegetarian',
            'label' => '素食（Vegetarian）',
            'kind' => 'exclusive',
            'group_label' => '純素食店',
            'osm_tag' => 'diet:vegetarian',
            'osm_values' => ['only'],
        ],
        [
            'code' => 'ovo_lacto',
            'label' => '蛋奶素（Ovo-Lacto）',
            'kind' => 'exclusive',
            'group_label' => '純素食店',
            'osm_tag' => null,
            'osm_values' => [],
        ],
        [
            'code' => 'lacto',
            'label' => '奶素（Lacto）',
            'kind' => 'exclusive',
            'group_label' => '純素食店',
            'osm_tag' => null,
            'osm_values' => [],
        ],
        [
            'code' => 'ovo',
            'label' => '蛋素（Ovo）',
            'kind' => 'exclusive',
            'group_label' => '純素食店',
            'osm_tag' => null,
            'osm_values' => [],
        ],
        [
            'code' => 'vegan_friendly',
            'label' => '全素友善',
            'kind' => 'friendly',
            'group_label' => '素食友善',
            'osm_tag' => 'diet:vegan',
            'osm_values' => ['yes'],
        ],
        [
            'code' => 'vegetarian_friendly',
            'label' => '素食友善',
            'kind' => 'friendly',
            'group_label' => '素食友善',
            'osm_tag' => 'diet:vegetarian',
            'osm_values' => ['yes'],
        ],
    ],

    /*
    | Overpass 收錄模式。EXTERNAL_API_SYNC_BBOXES 的 @後面必須是這裡的 key。
    | only：只收 diet:*=only。yes：yes 與 only 都收（純素食店是友善集合的子集）。
    */
    'sync_modes' => [
        'only' => [
            'osm_values' => ['only'],
        ],
        'yes' => [
            'osm_values' => ['yes', 'only'],
        ],
    ],

    'default_sync_mode' => 'only',

    // 例如 ['vegan' => ['vegetarian']]：全素店同時掛素食。預設關，要用再打開。
    'implies' => [],

    'venue_scope' => [
        'param' => 'venue_scope',
        'default' => 'exclusive',
        'group_label' => '店家類型',
        'values' => [
            'exclusive' => [
                'label' => '純素食店',
                'include_kinds' => ['exclusive'],
                'exclude_kinds' => [],
            ],
            'friendly' => [
                'label' => '素食友善',
                'include_kinds' => ['friendly'],
                'exclude_kinds' => ['exclusive'],
            ],
            'all' => [
                'label' => '全部',
                'include_kinds' => null,
                'exclude_kinds' => [],
            ],
        ],
    ],

    'menu_item_diets' => [
        ['code' => 'vegan', 'label' => '全素'],
        ['code' => 'vegetarian', 'label' => '素食'],
        ['code' => 'non_vegetarian', 'label' => '葷食'],
        ['code' => 'unknown', 'label' => '未標示'],
    ],

    /*
    | OSM 匯入寫 external_source 時，依店家 kind 取分。exclusive 才算「店家明確標示素食」；
    | friendly 只是「有素食選項」，不該同分。沒對上 kind 時用 exclusive 那檔（保守）。
    */
    'confidence' => [
        'external_source' => [
            'exclusive' => 10,
            'friendly' => 5,
        ],
    ],

    'copy' => [
        'exclusive' => [
            'badge' => '素食餐廳',
            'short' => '整間店都是素食',
        ],
        'friendly' => [
            'badge' => '素食友善',
            'short' => '菜單有素食（無肉）選項',
        ],
    ],

    'osm_amenities' => ['restaurant', 'cafe'],

    /*
    | OSM 標籤 → features.code。值的白名單：只看 key 存在會把 outdoor_seating=no
    | 標成有戶外座位。沒列在這裡的特色（parking、family_friendly）OSM 沒可用標籤。
    */
    'osm_features' => [
        'takeaway' => ['feature' => 'takeout', 'values' => ['yes', 'only']],
        'delivery' => ['feature' => 'delivery', 'values' => ['yes', 'only']],
        'outdoor_seating' => ['feature' => 'outdoor_seating', 'values' => [
            'yes', 'patio', 'veranda', 'terrace', 'garden', 'rooftop', 'sidewalk', 'street', 'pedestrian_zone',
        ]],
        'internet_access' => ['feature' => 'wifi', 'values' => ['wlan', 'yes']],
        'reservation' => ['feature' => 'reservation', 'values' => ['yes', 'required', 'recommended']],
        'dog' => ['feature' => 'pet_friendly', 'values' => ['yes', 'leashed']],
    ],

];
