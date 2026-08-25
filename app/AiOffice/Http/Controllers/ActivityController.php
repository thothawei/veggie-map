<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Resources\ActivityResource;
use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Project;
use App\AiOffice\Services\ActivityStream;
use App\AiOffice\Services\StreamConnectionLimiter;
use App\AiOffice\Services\StreamTicketService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 規格第 35／36 節：事件流的 REST 讀取與 SSE 推送。
 *
 * 兩條路徑刻意共用 ActivityStream，列表與串流不會給出不一樣的事件集合。
 */
class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityStream $stream,
        private readonly StreamTicketService $tickets,
        private readonly StreamConnectionLimiter $limiter,
    ) {}

    /**
     * 補資料用的分頁列表。前端斷線重連時先用 `after_id` 把漏掉的事件補齊，
     * 再開新的 SSE——只靠 SSE 的話，斷線視窗內的事件永遠追不回來。
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $filters = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'type' => ['nullable', 'string', 'max:100'],
            'task_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $afterId = (int) ($filters['after_id'] ?? 0);
        $perPage = (int) ($filters['per_page'] ?? 50);

        $query = Activity::query()
            ->where('project_id', $project->id)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['task_id'] ?? null, fn ($q, $taskId) => $q->where('task_id', $taskId));

        // 帶 after_id 是「補漏」語意：要照時間往前接，所以升冪；
        // 不帶就是看最新的，降冪。兩種順序混用會讓前端接不起來。
        $activities = $query
            ->orderBy('id', $afterId > 0 ? 'asc' : 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ActivityResource::collection($activities)->resolve(),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'latest_id' => $this->stream->latestId($project),
            ],
        ]);
    }

    /** 換一張開 SSE 用的一次性票（見 StreamTicketService 的說明）。 */
    public function ticket(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => [
                'ticket' => $this->tickets->issue($request->user(), $project),
                'expires_in' => $this->tickets->ttl(),
                'latest_id' => $this->stream->latestId($project),
            ],
        ]);
    }

    /**
     * SSE 串流。認證走 ticket 而不是 auth:sanctum——EventSource 不能帶標頭。
     * 連線壽命、每人連線數、每輪批次量都在 config('ai_office.events')。
     */
    public function stream(Request $request, Project $project): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'ticket' => ['required', 'string', 'max:200'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $this->tickets->redeem($validated['ticket'], $project);

        if ($user === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => '串流票無效或已過期'],
            ], 401);
        }

        // 票是用有效登入換來的，但角色可能在這段期間被降級，所以這裡再查一次。
        Auth::setUser($user);

        if (! $user->canAccessAiOffice()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => '沒有存取 AI Office 的權限'],
            ], 403);
        }

        if (! $this->limiter->acquire($user->id)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TOO_MANY_CONNECTIONS', 'message' => '同時開啟的事件串流過多'],
            ], 429);
        }

        // Last-Event-ID 是瀏覽器自動重連時帶回來的，優先於 query——重連不該漏事件。
        $lastEventId = $request->header('Last-Event-ID');
        $afterId = is_numeric($lastEventId)
            ? (int) $lastEventId
            : (int) ($validated['after_id'] ?? $this->stream->latestId($project));

        return response()->stream(
            fn () => $this->pump($project, $afterId, $user->id),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, private',
                'Connection' => 'keep-alive',
                // nginx 預設會緩衝 FastCGI 回應，事件會卡在 proxy 裡不吐出來。
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function pump(Project $project, int $afterId, int $userId): void
    {
        $events = config('ai_office.events');
        $deadline = microtime(true) + max(1, (int) $events['max_duration_seconds']);
        $sleepMicros = max(100, (int) $events['poll_interval_ms']) * 1000;
        $batch = max(1, (int) $events['batch_size']);

        try {
            do {
                foreach ($this->stream->since($project, $afterId, $batch) as $activity) {
                    $afterId = (int) $activity->id;
                    $this->emit(
                        (string) $activity->id,
                        'activity',
                        (new ActivityResource($activity))->resolve(request()),
                    );
                }

                // 沒有新事件也要送東西：中間的 proxy 與瀏覽器都會把完全安靜的連線
                // 當成掛掉。心跳同時帶回游標，前端斷線後知道要從哪裡補。
                $this->emit(null, 'heartbeat', ['last_id' => $afterId]);

                if (connection_aborted() === 1) {
                    return;
                }

                if (microtime(true) >= $deadline) {
                    break;
                }

                usleep($sleepMicros);
            } while (microtime(true) < $deadline);

            // 主動收尾：告訴前端這是壽命到期不是出錯，帶著 last_id 重連即可。
            $this->emit(null, 'reconnect', ['last_id' => $afterId]);
        } finally {
            $this->limiter->release($userId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(?string $id, string $event, array $data): void
    {
        if ($id !== null) {
            echo 'id: '.$id."\n";
        }

        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
