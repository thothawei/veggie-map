<?php

namespace Tests\Feature\AiOffice;

use Anthropic\Client;
use App\AiOffice\Llm\ClaudeProvider;
use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * 規格第 4 節：provider 可替換，而且不能悄悄退回預設值。
 */
class LlmProviderBindingTest extends TestCase
{
    private function resolveProvider(): LlmProviderInterface
    {
        // 綁定是單例，改完設定要先忘掉既有實例才會重新解析。
        $this->app->forgetInstance(LlmProviderInterface::class);

        return $this->app->make(LlmProviderInterface::class);
    }

    public function test_the_default_provider_is_mock_so_tests_never_call_the_real_api(): void
    {
        $this->assertSame('mock', config('ai_office.llm.default_provider'));
        $this->assertInstanceOf(MockProvider::class, $this->resolveProvider());
    }

    public function test_an_unknown_provider_name_throws_instead_of_falling_back(): void
    {
        // 靜默退回 mock 的話，把 provider 打成 'cluade' 會看起來一切正常，
        // 實際上一個字都沒送到 Claude——這種失敗最貴。
        config(['ai_office.llm.default_provider' => 'cluade']);

        $this->expectException(InvalidArgumentException::class);
        $this->resolveProvider();
    }

    public function test_claude_provider_refuses_to_start_without_an_api_key(): void
    {
        config([
            'ai_office.llm.default_provider' => 'claude',
            'ai_office.llm.providers.claude.api_key' => null,
        ]);

        $this->app->forgetInstance(Client::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY 未設定');
        $this->resolveProvider();
    }

    public function test_claude_provider_resolves_when_a_key_is_present(): void
    {
        config([
            'ai_office.llm.default_provider' => 'claude',
            'ai_office.llm.providers.claude.api_key' => 'sk-ant-test-key',
        ]);

        $this->app->forgetInstance(Client::class);

        $provider = $this->resolveProvider();

        $this->assertInstanceOf(ClaudeProvider::class, $provider);
        $this->assertSame('claude', $provider->name());
    }

    public function test_the_configured_model_default_is_opus_5(): void
    {
        $this->assertSame('claude-opus-5', config('ai_office.llm.providers.claude.model'));
    }
}
