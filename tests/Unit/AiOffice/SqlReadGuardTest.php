<?php

namespace Tests\Unit\AiOffice;

use App\AiOffice\Security\SqlReadGuard;
use App\AiOffice\Security\UnsafeQueryException;
use Tests\TestCase;

class SqlReadGuardTest extends TestCase
{
    public function test_select_is_allowed(): void
    {
        app(SqlReadGuard::class)->assertReadOnly('SELECT id FROM ai_office_projects LIMIT 1');
        $this->addToAssertionCount(1);
    }

    public function test_drop_is_rejected_by_prefix(): void
    {
        $this->expectException(UnsafeQueryException::class);
        app(SqlReadGuard::class)->assertReadOnly('DROP TABLE ai_office_projects');
    }

    public function test_explain_delete_is_rejected_by_keyword(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->expectExceptionMessage('delete');
        app(SqlReadGuard::class)->assertReadOnly('EXPLAIN DELETE FROM ai_office_projects');
    }

    public function test_multiple_statements_are_rejected(): void
    {
        $this->expectException(UnsafeQueryException::class);
        app(SqlReadGuard::class)->assertReadOnly('SELECT 1; DROP TABLE ai_office_projects');
    }

    public function test_comments_cannot_hide_a_write(): void
    {
        $this->expectException(UnsafeQueryException::class);
        app(SqlReadGuard::class)->assertReadOnly("SELECT 1; /* comment */\nDELETE FROM ai_office_projects");
    }

    public function test_removing_select_from_config_makes_it_invalid(): void
    {
        $this->assertNotEmpty(config('ai_office.tools.database.allowed_prefixes'));

        config(['ai_office.tools.database.allowed_prefixes' => ['explain', 'describe']]);

        $this->expectException(UnsafeQueryException::class);
        app(SqlReadGuard::class)->assertReadOnly('SELECT 1');
    }
}
