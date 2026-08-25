<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Project;
use Illuminate\Support\Collection;

/**
 * 事件流的讀取端（規格第 35～36 節）。REST 分頁與 SSE 增量推送共用同一份查詢，
 * 兩邊才不會出現「列表看得到、串流看不到」的差異。
 *
 * 一律用自增 id 當游標，不用 created_at：同一秒可以有很多筆事件，用時間當游標
 * 會在毫秒邊界上漏事件或重送。
 */
class ActivityStream
{
    /**
     * @return Collection<int, Activity>
     */
    public function since(Project $project, int $afterId, int $limit): Collection
    {
        return Activity::query()
            ->where('project_id', $project->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** 串流開始前的游標：只想收「從現在起」的事件時用它，避免一次灌出整段歷史。 */
    public function latestId(Project $project): int
    {
        return (int) Activity::query()->where('project_id', $project->id)->max('id');
    }
}
