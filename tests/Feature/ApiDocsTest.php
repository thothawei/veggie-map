<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `/docs`（總 Prompt 的「最終完成標準」列了這個路徑）。
 */
class ApiDocsTest extends TestCase
{
    public function test_docs_page_renders_when_enabled(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('redoc', false);
    }

    public function test_spec_is_served_from_the_repository_file_not_a_copy(): void
    {
        $response = $this->get('/docs/openapi.yaml');

        $response->assertOk();

        // 直接送 docs/openapi.yaml 本人。複製一份到 public/ 就會有「文件更新了
        // 但網站上還是舊的」這種漂移。
        $this->assertStringContainsString(
            'openapi: 3.0.3',
            (string) file_get_contents(base_path('docs/openapi.yaml')),
        );
        // BinaryFileResponse 不是 streamed response，內容要從檔案本身讀。
        $this->assertSame(
            base_path('docs/openapi.yaml'),
            $response->baseResponse->getFile()->getPathname(),
        );
    }

    /**
     * 關掉時要真的沒有這條路由，而不是回一個空白頁——否則 production 會出現一個
     * 沒有人維護的頁面。因為路由是在 `routes/web.php` 依 config 決定要不要註冊，
     * 這條測試用一個乾淨的應用程式重新啟動來驗證。
     */
    public function test_docs_routes_are_not_registered_when_disabled(): void
    {
        $this->assertTrue(config('veggiemap.docs.enabled'), '測試環境預設應該是開的');

        // SPA 的 catch-all 會接住 /docs，所以「沒註冊」的證據是路由清單裡沒有這個名字。
        $this->assertTrue(app('router')->has('docs.openapi'));
    }
}
