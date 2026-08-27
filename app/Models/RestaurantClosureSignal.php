<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property string $signal
 * @property array<string, mixed>|null $metadata
 * @property CarbonInterface|null $detected_at
 * @property string|null $resolution
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property-read Restaurant|null $restaurant
 */
class RestaurantClosureSignal extends Model
{
    use HasFactory;

    /**
     * 訊號種類。每一種都必須是「可以指著說出理由」的觀察，不是分數或直覺。
     *
     * osm_node_missing：OSM 上查不到這個節點了。**單獨看不足以下架**——節點會因為
     *                   被合併進 way、改成別的 element 或誤刪而消失，所以它走人工審核
     *                   而不是自動下架（OSM 明確標了 disused: 的那種才自動下架）。
     *
     * 只列已經實作的種類。想加「官網連不上」之類的新訊號時，這裡加一個值、
     * 偵測端寫入、Admin 頁自然就顯示得出來——不要先把常數宣告好放著等，
     * 那會讓人以為那個訊號已經在跑了。
     *
     * @var list<string>
     */
    public const SIGNALS = ['osm_node_missing'];

    /** @var list<string> */
    public const RESOLUTIONS = ['confirmed', 'dismissed'];

    protected $fillable = [
        'restaurant_id', 'signal', 'metadata', 'detected_at',
        'resolution', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
