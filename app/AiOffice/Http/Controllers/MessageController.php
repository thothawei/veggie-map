<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Models\Message;
use App\AiOffice\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 規格第 34 節的 Agent 之間往來訊息（唯讀）。
 *
 * 只有讀：訊息是 Agent 協作**產生**的紀錄，開放 API 寫入等於讓人偽造 Agent 的發言，
 * 那會讓這條時間軸失去它唯一的價值。
 */
class MessageController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $filters = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'task_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $afterId = (int) ($filters['after_id'] ?? 0);
        $perPage = (int) ($filters['per_page'] ?? 50);

        $messages = Message::query()
            ->where('project_id', $project->id)
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->when(isset($filters['task_id']), fn ($query) => $query->where('task_id', $filters['task_id']))
            // 收發雙方的名字是這份清單的主體，逐筆補查就是 N+1。
            ->with(['sender:id,name,role', 'recipient:id,name,role'])
            ->orderBy('id')
            ->limit($perPage)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn (Message $message) => [
                'id' => $message->id,
                'task_id' => $message->task_id,
                'content' => $message->content,
                'from' => $message->sender === null ? null : [
                    'id' => $message->sender->id,
                    'name' => $message->sender->name,
                    'role' => $message->sender->role,
                ],
                'to' => $message->recipient === null ? null : [
                    'id' => $message->recipient->id,
                    'name' => $message->recipient->name,
                    'role' => $message->recipient->role,
                ],
                'created_at' => $message->created_at,
            ])->all(),
            'meta' => [
                // 前端要接著往下拉時從這個 id 之後開始，跟 activities 同一套約定。
                'last_id' => $messages->last()?->id,
            ],
        ]);
    }
}
