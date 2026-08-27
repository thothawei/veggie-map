<?php

namespace App\Services\External;

/**
 * 測試用。讓「歇業就自動下架」整條鏈路可以在不打外部 API 的情況下驗證
 * （總 Prompt 第十四節：API 不穩時要有 Mock 才 demo 得下去）。
 */
class MockBusinessStatusProvider implements BusinessStatusProviderInterface
{
    /**
     * @param  array<int, BusinessStatus>  $statuses  restaurant id => 狀態
     */
    public function __construct(private array $statuses = []) {}

    public function name(): string
    {
        return 'mock';
    }

    public function setStatus(int $restaurantId, BusinessStatus $status): void
    {
        $this->statuses[$restaurantId] = $status;
    }

    public function statusFor(iterable $restaurants): array
    {
        $result = [];

        foreach ($restaurants as $restaurant) {
            // 沒設定的一律 Unknown，跟真的 provider 一致：不知道就是不知道。
            $result[$restaurant->id] = $this->statuses[$restaurant->id] ?? BusinessStatus::Unknown;
        }

        return $result;
    }
}
