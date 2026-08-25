<?php

namespace App\AiOffice\Llm;

/**
 * 規格第 4 節：不要把 Claude API 寫死在 Agent 裡。
 *
 * AgentRuntime 只認識這個介面，換 provider 只改 AiOfficeServiceProvider 的一行綁定
 * ——跟這個 repo 既有的 RestaurantProviderInterface／RecommendationServiceInterface
 * 同一套做法。
 */
interface LlmProviderInterface
{
    /** provider 名稱，寫進 token_usages 用（claude／mock…）。 */
    public function name(): string;

    public function send(LlmRequest $request): LlmResponse;
}
