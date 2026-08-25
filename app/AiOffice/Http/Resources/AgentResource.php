<?php

namespace App\AiOffice\Http\Resources;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Agent */
class AgentResource extends JsonResource
{
    /**
     * 詳細模式才回傳 system prompt 與權限表——列表頁不需要，
     * 而且 prompt 動輒上千字，塞在列表裡只是浪費頻寬。
     */
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'avatar' => $this->avatar,
            'description' => $this->description,
            // 真實狀態，由 AgentRuntime 寫入；前端只讀（規格第 7、46 節）。
            'status' => $this->status,
            'model_provider' => $this->model_provider,
            'model_name' => $this->model_name,
            'max_concurrency' => $this->max_concurrency,
        ];

        if (! $this->detailed) {
            return $payload;
        }

        return $payload + [
            'system_prompt' => $this->system_prompt,
            'tools' => $this->tools->pluck('tool')->all(),
            'permissions' => $this->permissions
                ->mapWithKeys(fn (AgentPermission $permission) => [$permission->ability => $permission->effect])
                ->all(),
            'active_task_count' => $this->activeTaskCount(),
        ];
    }
}
