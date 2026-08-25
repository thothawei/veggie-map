<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SSE 的一次性入場票。瀏覽器的 `EventSource` 不能帶 Authorization 標頭，
 * 但把 Sanctum token 放進 query string 等於把長期憑證寫進 access log 與
 * 瀏覽器歷史。折衷是：先用 Bearer token 換一張短期票（預設 60 秒、只能用一次、
 * 綁定使用者與專案），再拿它開串流。
 */
class StreamTicketService
{
    private const PREFIX = 'ai-office:sse-ticket:';

    public function issue(User $user, Project $project): string
    {
        $ticket = Str::random(48);

        Cache::put(
            self::PREFIX.hash('sha256', $ticket),
            ['user_id' => $user->id, 'project_id' => $project->id],
            $this->ttl(),
        );

        return $ticket;
    }

    /**
     * 兌換成功就立刻作廢：重播同一張票開第二條連線會被擋掉。
     * 票不對、過期、或指向別的專案，一律回 null 讓呼叫端回 401。
     */
    public function redeem(string $ticket, Project $project): ?User
    {
        $key = self::PREFIX.hash('sha256', $ticket);
        $payload = Cache::get($key);

        if (! is_array($payload) || ($payload['project_id'] ?? null) !== $project->id) {
            return null;
        }

        Cache::forget($key);

        return User::find($payload['user_id'] ?? null);
    }

    public function ttl(): int
    {
        return max(5, (int) config('ai_office.events.ticket_ttl_seconds', 60));
    }
}
