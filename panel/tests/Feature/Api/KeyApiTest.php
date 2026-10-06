<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class KeyApiTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    public function test_index_hides_system_keys(): void
    {
        $this->getJson('/api/keys')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'analytics-etl')
            ->assertJsonPath('data.1.id', self::WEB_APP_KEY_ID);
    }

    public function test_create_returns_parsed_credentials(): void
    {
        $this->postJson('/api/keys', ['name' => 'mobile-app'])
            ->assertCreated()
            ->assertJsonPath('id', 'GK9e3b8d1f2a4c6e8b0d2f4a6c')
            ->assertJsonPath('credentials.secret_access_key', '3f1c9a7e5b2d8f4a6c0e2b4d6f8a1c3e5b7d9f0a2c4e6b8d0f1a3c5e7b9d2f4a');
    }

    public function test_system_keys_are_protected(): void
    {
        $this->getJson('/api/keys/'.self::PANEL_KEY_ID)->assertForbidden();
        $this->deleteJson('/api/keys/'.self::PANEL_KEY_ID)->assertForbidden();
        $this->deleteJson('/api/buckets/media/keys/'.self::PANEL_KEY_ID.'?read=1')->assertForbidden();

        $this->assertJohnnyDidntRun(['key', 'delete']);
        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_id_prefixes_do_not_match_keys(): void
    {
        $this->deleteJson('/api/keys/GK1f85')->assertNotFound();
        $this->postJson('/api/buckets/media/keys', ['key_id' => 'GK1f85', 'read' => true])->assertNotFound();

        $this->assertJohnnyDidntRun(['key', 'delete']);
        $this->assertJohnnyDidntRun(['bucket', 'allow']);
    }

    public function test_permissions_can_be_granted_and_revoked(): void
    {
        $this->postJson('/api/buckets/media/keys', ['key_id' => self::WEB_APP_KEY_ID, 'read' => true, 'write' => true])
            ->assertCreated()
            ->assertJson(['key_id' => self::WEB_APP_KEY_ID, 'read' => true, 'write' => true, 'owner' => false]);

        $this->deleteJson('/api/buckets/media/keys/'.self::WEB_APP_KEY_ID.'?write=1')->assertNoContent();

        $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--write', 'media', '--key', self::WEB_APP_KEY_ID]);
    }
}
