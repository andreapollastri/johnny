<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tokens_can_be_issued_and_revoked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->followingRedirects()
            ->post('/settings/tokens', ['name' => 'Terraform'])
            ->assertOk()
            ->assertSee('New API token')
            ->assertSee('Terraform');

        $token = $user->tokens()->sole();

        $this->delete("/settings/tokens/{$token->id}")->assertRedirect('/settings');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_users_cannot_revoke_tokens_of_other_users(): void
    {
        $other = User::factory()->create();
        $token = $other->createToken('theirs')->accessToken;

        $this->actingAs(User::factory()->create())
            ->delete("/settings/tokens/{$token->id}")
            ->assertSessionHasErrors('token');

        $this->assertSame(1, $other->tokens()->count());
    }
}
