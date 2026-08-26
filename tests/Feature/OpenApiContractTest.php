<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * OpenAPI 規格與實際路由的**漂移偵測**。
 *
 * 這個專案反覆踩的就是「文件與實作不一致」：`docs/api.md` 一度列了兩個不存在的
 * Policy、`open_now` 這個參數在文件上活了好幾個月卻沒有實作、`deployment.md`
 * 的缺口表停在三個月前的狀態。那些都是人去對照才發現的。
 *
 * 這條測試把對照自動化：**每一條 `/api/v1` 路由都要在 openapi.yaml 裡有對應的
 * path + method，反過來也一樣**。它不驗 schema 的每個欄位（那需要另外一套工具，
 * 而且維護成本會超過它抓到的問題），只守住最容易漂移、也最誤導人的那一層。
 *
 * 新增端點時這條會紅——那是它的目的。補文件，不是把端點加進下面的豁免清單。
 */
class OpenApiContractTest extends TestCase
{
    /**
     * 刻意不寫進 OpenAPI 的路由。
     *
     * SSE 串流無法用 OpenAPI 的 response schema 好好描述（它是一條長連線、
     * 內容是 `text/event-stream` 的事件序列），硬寫一份會比沒有更誤導。
     * `docs/api.md` 有專門一節說明它怎麼用。
     *
     * @var list<string>
     */
    private const INTENTIONALLY_UNDOCUMENTED = [
        'GET api/v1/ai-office/projects/{project}/events',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    private function spec(): array
    {
        /** @var array<string, array<string, mixed>> $spec */
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));

        return $spec;
    }

    /**
     * @return list<string> 例如 `GET api/v1/restaurants/{restaurant}`
     */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->spec()['paths'] ?? [] as $path => $methods) {
            foreach (array_keys((array) $methods) as $method) {
                // 路徑參數的名字兩邊未必一樣（OpenAPI 寫 {id}、Laravel 寫
                // {restaurant}），所以一律正規化成 {} 再比對——這條測試守的是
                // 「有沒有這條端點」，不是「參數叫什麼名字」。
                $normalized = preg_replace('/\{[^}]+\}/', '{}', 'api/v1'.$path);
                $operations[] = strtoupper($method).' '.$normalized;
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                // HEAD 是 Laravel 自動跟著 GET 註冊的，OPTIONS 是 CORS 用的，
                // 兩者都不是 API 契約的一部分。
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                if (in_array($method.' '.$uri, self::INTENTIONALLY_UNDOCUMENTED, true)) {
                    continue;
                }

                $operations[] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $uri);
            }
        }

        $operations = array_values(array_unique($operations));
        sort($operations);

        return $operations;
    }

    public function test_every_registered_route_is_documented(): void
    {
        $missing = array_values(array_diff($this->registeredOperations(), $this->documentedOperations()));

        $this->assertSame([], $missing, "這些端點存在但 docs/openapi.yaml 沒有寫：\n".implode("\n", $missing));
    }

    public function test_every_documented_operation_actually_exists(): void
    {
        $ghosts = array_values(array_diff($this->documentedOperations(), $this->registeredOperations()));

        $this->assertSame(
            [],
            $ghosts,
            "docs/openapi.yaml 寫了這些端點但實際上不存在（比缺文件更糟——使用端會照著寫然後拿到 404）：\n"
                .implode("\n", $ghosts),
        );
    }

    /**
     * 上面兩條若因為「兩邊都是空的」而通過，就是假保護。
     */
    public function test_the_comparison_is_not_vacuous(): void
    {
        $this->assertGreaterThan(20, count($this->registeredOperations()));
        $this->assertGreaterThan(20, count($this->documentedOperations()));
    }
}
