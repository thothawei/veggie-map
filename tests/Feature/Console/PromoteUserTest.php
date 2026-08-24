<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotes_existing_user_to_admin(): void
    {
        $user = User::factory()->create(['email' => 'promote-me@example.com', 'role' => 'user']);

        $this->artisan('users:promote', ['email' => 'promote-me@example.com'])
            ->assertExitCode(0);

        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_already_admin_is_a_no_op_success(): void
    {
        $user = User::factory()->create(['email' => 'already-admin@example.com', 'role' => 'admin']);

        $this->artisan('users:promote', ['email' => 'already-admin@example.com'])
            ->assertExitCode(0);

        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_unknown_email_fails(): void
    {
        $this->artisan('users:promote', ['email' => 'nobody@example.com'])
            ->assertExitCode(1);
    }
}
