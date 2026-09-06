<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * 一筆零結果查詢（A8）。見 database/migrations 對應的 migration 說明「為什麼」。
 *
 * @property int $id
 * @property string|null $keyword
 * @property string|null $normalized
 * @property int $result_count
 * @property bool $had_filters
 * @property CarbonInterface $created_at
 */
class SearchMiss extends Model
{
    /** 這張表沒有 updated_at——一筆紀錄寫入之後不會被修改，多維護一欄沒有意義。 */
    public $timestamps = false;

    protected $fillable = ['keyword', 'normalized', 'result_count', 'had_filters', 'created_at'];

    protected function casts(): array
    {
        return [
            'had_filters' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
