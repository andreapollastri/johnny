<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class KeyManagementTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    public function test_index_lists_user_keys_only(): void
    {
        $this->get('/keys')
            ->assertOk()
            ->assertSeeInOrder(['analytics-etl', 'web-app'])
            ->assertSee('Oct 7, 2026')
            ->assertDontSee('johnny-backup');
    }

    public function test_new_key_credentials_are_shown_once(): void
    {
        $this->followingRedirects()
            ->post('/keys', ['name' => 'mobile-app'])
            ->assertOk()
            ->assertSee('New key: mobile-app')
            ->assertSee('GK9e3b8d1f2a4c6e8b0d2f4a6c')
            ->assertSee('3f1c9a7e5b2d8f4a6c0e2b4d6f8a1c3e5b7d9f0a2c4e6b8d0f1a3c5e7b9d2f4a');

        $this->assertJohnnyRan(['key', 'create', 'mobile-app']);
        $this->get('/keys')->assertDontSee('3f1c9a7e5b2d8f4a6c0e2b4d6f8a1c3e5b7d9f0a2c4e6b8d0f1a3c5e7b9d2f4a');
    }

    public function test_system_key_names_are_reserved(): void
    {
        $this->post('/keys', ['name' => 'johnny-backup'])->assertSessionHasErrors('name');

        $this->assertJohnnyDidntRun(['key', 'create']);
    }

    public function test_user_keys_can_be_deleted(): void
    {
        $this->delete('/keys', ['key_id' => self::WEB_APP_KEY_ID])->assertRedirect('/keys');

        $this->assertJohnnyRan(['key', 'delete', '--yes', self::WEB_APP_KEY_ID]);
    }

    public function test_system_keys_cannot_be_deleted(): void
    {
        $this->delete('/keys', ['key_id' => self::PANEL_KEY_ID])->assertSessionHasErrors('key_id');

        $this->assertJohnnyDidntRun(['key', 'delete']);
    }

    public function test_an_id_prefix_cannot_be_used_to_delete_a_system_key(): void
    {
        // Regression: Garage resolves "GK1f85" to johnny-default, which used to slip past the guard.
        $this->delete('/keys', ['key_id' => 'GK1f85'])->assertSessionHasErrors(['key_id' => 'Key not found.']);

        $this->assertJohnnyDidntRun(['key', 'delete']);
    }
}
