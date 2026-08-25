<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property float|null $distance `RestaurantRepository::search()` 的 subquery 計算欄位，
 *                                只有半徑搜尋時才存在，不是資料表實際欄位。
 * @property float|null $recommendation_score `RuleBasedRecommendationService::rank()`
 *                                            動態設定的分數，不是資料表實際欄位。
 */
class Restaurant extends Model
{
    use HasFactory;

    // `location`（POINT SRID 4326，NOT NULL）沒有原生 Eloquent cast，寫入時一律用
    // DB::raw('ST_SRID(POINT(lng, lat), 4326)') 而不是直接塞座標陣列/字串——它可以被
    // mass assignment 帶進來，但值必須是呼叫端算好的 raw expression（見 RestaurantFactory）。
    // 查詢半徑搜尋一樣走 raw expression／ST_Distance_Sphere，留到 Phase 3 Repository 實作。
    //
    // 重要陷阱（實測過，不是猜的）：MySQL 8 對 SRID 4326 會強制套用 EPSG:4326 定義的
    // 座標軸順序（緯度在前、經度在後）。`ST_SRID(POINT(lng, lat), 4326)` 這個寫法能得到
    // 正確結果，是因為 POINT(x, y) 先以 SRID 0（笛卡兒座標，x=經度在前）建立幾何，
    // 「之後」再用 ST_SRID() 綁定 4326 時，MySQL 會依 4326 的軸順序重新解讀並儲存成
    // 「緯度在前」——所以 ST_AsText() 印出來看起來像是「反過來」了，那是對的，不是 bug。
    // 反過來，如果直接寫 ST_GeomFromText("POINT($lng $lat)", 4326)，順序就會是錯的
    // （會把經度當成緯度，數值一旦超過 90 就直接報錯，數值沒超過 90 則會安靜地把整個
    // 地圖左右鏡射，不會報錯，很難發現）。Phase 3 寫查詢時，寫入／查詢兩邊都要用
    // 「先 POINT(lng, lat) 再 ST_SRID(..., 4326)」這個順序，不要憑座標軸直覺去改。
    protected $fillable = [
        'name',
        'slug',
        'description',
        'address',
        'city',
        'district',
        'latitude',
        'longitude',
        'location',
        'phone',
        'website',
        'price_level',
        'rating',
        'rating_count',
        'source',
        'source_id',
        'status',
        'is_possible_duplicate',
        'opening_hours',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'rating' => 'decimal:2',
            'rating_count' => 'integer',
            'price_level' => 'integer',
            'is_possible_duplicate' => 'boolean',
        ];
    }

    /**
     * 列表／詳情只出 active。評論、收藏、回報走 implicit binding，若不擋 pending
     * 會寫得進去、點進去卻 404。
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('status', 'active')
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    /**
     * @return BelongsToMany<DietType, $this>
     */
    public function dietTypes(): BelongsToMany
    {
        return $this->belongsToMany(DietType::class, 'restaurant_diet_types')
            ->withPivot('created_at');
    }

    /**
     * @return BelongsToMany<Feature, $this>
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'restaurant_features')
            ->withPivot('created_at');
    }

    /**
     * 解析後的營業時段，`open_now` 篩選與詳情頁的一週時間表都讀這裡；
     * `opening_hours` 欄位保留 OSM 原始字串供顯示與日後重新解析。
     *
     * @return HasMany<RestaurantOpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(RestaurantOpeningHour::class);
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * @return HasMany<RestaurantVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(RestaurantVerification::class);
    }

    /**
     * @return HasOne<RestaurantConfidenceScore, $this>
     */
    public function confidenceScore(): HasOne
    {
        return $this->hasOne(RestaurantConfidenceScore::class);
    }

    /**
     * @return HasMany<RestaurantReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(RestaurantReport::class);
    }

    /**
     * @return HasMany<Favorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
