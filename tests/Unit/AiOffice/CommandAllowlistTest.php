<?php

namespace Tests\Unit\AiOffice;

use App\AiOffice\Security\CommandAllowlist;
use App\AiOffice\Security\CommandDeniedException;
use Tests\TestCase;

class CommandAllowlistTest extends TestCase
{
    public function test_an_allowlisted_command_with_safe_flags_is_accepted(): void
    {
        app(CommandAllowlist::class)->assertAllowed('php artisan test --filter=FileToolTest');
        $this->addToAssertionCount(1);
    }

    public function test_commands_not_on_the_allowlist_are_rejected(): void
    {
        $this->expectException(CommandDeniedException::class);
        app(CommandAllowlist::class)->assertAllowed('php -r "system(\"id\");"');
    }

    public function test_denylist_wins_even_when_the_command_is_on_the_allowlist(): void
    {
        config(['ai_office.tools.terminal.allowlist' => ['rm -rf /']]);

        $this->expectException(CommandDeniedException::class);
        $this->expectExceptionMessage('denylist');
        app(CommandAllowlist::class)->assertAllowed('rm -rf /');
    }

    public function test_removing_denylist_lets_an_allowlisted_destructive_command_through(): void
    {
        // 反向：denylist 拿掉之後，同一個 allowlist 項目會過——證明擋下來的是 denylist。
        config([
            'ai_office.tools.terminal.allowlist' => ['rm -rf /'],
            'ai_office.tools.terminal.denylist_patterns' => [],
        ]);

        app(CommandAllowlist::class)->assertAllowed('rm -rf /');
        $this->addToAssertionCount(1);
    }

    public function test_shell_metacharacters_are_rejected(): void
    {
        $this->expectException(CommandDeniedException::class);
        $this->expectExceptionMessage('中介字元');
        app(CommandAllowlist::class)->assertAllowed('php artisan test ; echo pwned');
    }

    public function test_allowlist_comes_from_config(): void
    {
        config(['ai_office.tools.terminal.allowlist' => ['uname']]);

        app(CommandAllowlist::class)->assertAllowed('uname');

        $this->expectException(CommandDeniedException::class);
        app(CommandAllowlist::class)->assertAllowed('php artisan test');
    }
}
