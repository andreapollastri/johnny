<?php

namespace Tests\Feature\Api;

use App\Models\GlobalKey;
use App\Models\User;
use App\Services\GarageS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

/**
 * Scopes over the API: keys for chosen buckets, keys for all buckets, exact grants.
 */
class KeyScopesApiTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    /** The key of tests/Fixtures/garage-2.4/key-create.txt. */
    private const NEW_KEY_ID = 'GK9e3b8d1f2a4c6e8b0d2f4a6c';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->fakeJohnny(['key info' => $this->fixture('key-info')]);
    }

    public function test_scopes_are_listed_with_their_permissions(): void
    {
        $this->getJson('/api/scopes')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.id', 'read')
            ->assertJsonPath('data.0.write', false)
            ->assertJsonPath('data.2.id', 'write')
            ->assertJsonPath('data.2.read', false)
            ->assertJsonPath('data.3.id', 'full')
            ->assertJsonPath('data.3.owner', true);
    }

    public function test_the_key_list_says_which_keys_reach_all_buckets(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'read']);

        $this->getJson('/api/keys')
            ->assertOk()
            ->assertJsonPath('data.0.all_buckets', false)
            ->assertJsonPath('data.0.scope', null)
            ->assertJsonPath('data.1.all_buckets', true)
            ->assertJsonPath('data.1.scope', 'read');
    }

    public function test_a_key_can_be_created_for_chosen_buckets(): void
    {
        $this->postJson('/api/keys', ['name' => 'reports', 'access' => 'buckets', 'scope' => 'read', 'buckets' => ['media']])
            ->assertCreated()
            ->assertJsonPath('id', self::NEW_KEY_ID)
            ->assertJsonPath('access', 'buckets')
            ->assertJsonPath('scope', 'read')
            ->assertJsonPath('buckets', ['media'])
            ->assertJsonPath('failed_buckets', [])
            ->assertJsonPath('credentials.access_key_id', self::NEW_KEY_ID);

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'media', '--key', self::NEW_KEY_ID]);
        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_a_key_is_not_created_for_buckets_that_do_not_exist(): void
    {
        $this->postJson('/api/keys', ['name' => 'reports', 'access' => 'buckets', 'buckets' => ['media', 'nope']])
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'nope');

        $this->postJson('/api/keys', ['name' => 'reports', 'access' => 'buckets'])->assertUnprocessable();
        $this->postJson('/api/keys', ['name' => 'reports', 'access' => 'all', 'scope' => 'delete'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->assertJohnnyDidntRun(['key', 'create']);
    }

    public function test_a_key_can_be_created_for_all_buckets(): void
    {
        $this->postJson('/api/keys', ['name' => 'backup-agent', 'access' => 'all', 'scope' => 'read-write'])
            ->assertCreated()
            ->assertJsonPath('access', 'all')
            ->assertJsonPath('scope', 'read-write');

        foreach (['default', 'media', 'tenant-acme'] as $bucket) {
            $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', $bucket, '--key', self::NEW_KEY_ID]);
        }

        $this->assertDatabaseHas('global_keys', ['key_id' => self::NEW_KEY_ID, 'scope' => 'read-write']);
    }

    public function test_a_plain_key_is_still_created_without_access(): void
    {
        $this->postJson('/api/keys', ['name' => 'mobile-app'])
            ->assertCreated()
            ->assertJsonPath('access', 'none')
            ->assertJsonPath('scope', null);

        $this->assertJohnnyDidntRun(['bucket', 'allow']);
    }

    public function test_a_key_shows_its_buckets_and_scopes(): void
    {
        $this->getJson('/api/keys/'.self::WEB_APP_KEY_ID)
            ->assertOk()
            ->assertJsonPath('data.name', 'web-app')
            ->assertJsonPath('data.all_buckets', false)
            ->assertJsonCount(3, 'data.buckets')
            ->assertJsonPath('data.buckets.0', ['bucket' => 'default', 'permissions' => 'write', 'scope' => 'write'])
            ->assertJsonPath('data.buckets.1.scope', 'read-write')
            ->assertJsonPath('data.buckets.2.scope', 'read');
    }

    public function test_a_key_can_be_granted_on_all_buckets_and_taken_back(): void
    {
        $this->putJson('/api/keys/'.self::WEB_APP_KEY_ID.'/all-buckets', ['scope' => 'read'])
            ->assertOk()
            ->assertJsonPath('data.all_buckets', true)
            ->assertJsonPath('data.scope', 'read')
            ->assertJsonPath('data.buckets', 3)
            ->assertJsonPath('data.failed_buckets', []);

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--write', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertDatabaseHas('global_keys', ['key_id' => self::WEB_APP_KEY_ID, 'scope' => 'read']);

        $this->deleteJson('/api/keys/'.self::WEB_APP_KEY_ID.'/all-buckets')->assertNoContent();
        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_system_keys_and_id_prefixes_cannot_be_made_global(): void
    {
        $this->putJson('/api/keys/'.self::PANEL_KEY_ID.'/all-buckets', ['scope' => 'read'])->assertForbidden();
        $this->putJson('/api/keys/GK1f85/all-buckets', ['scope' => 'read'])->assertNotFound();
        $this->deleteJson('/api/keys/'.self::PANEL_KEY_ID.'/all-buckets')->assertForbidden();

        $this->assertJohnnyDidntRun(['bucket', 'allow']);
        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_deleting_a_key_forgets_that_it_was_global(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'read']);

        $this->deleteJson('/api/keys/'.self::WEB_APP_KEY_ID)->assertNoContent();

        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_a_scope_on_a_bucket_is_exact(): void
    {
        $this->postJson('/api/buckets/media/keys', ['key_id' => self::WEB_APP_KEY_ID, 'scope' => 'write'])
            ->assertCreated()
            ->assertJson(['bucket' => 'media', 'scope' => 'write', 'read' => false, 'write' => true, 'owner' => false]);

        $this->assertJohnnyRan(['bucket', 'allow', '--write', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--read', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);
    }

    public function test_a_scope_cannot_be_set_on_a_system_key(): void
    {
        $this->postJson('/api/buckets/media/keys', ['key_id' => self::PANEL_KEY_ID, 'scope' => 'read'])->assertForbidden();
        $this->postJson('/api/buckets/media/keys', ['key_id' => self::WEB_APP_KEY_ID, 'scope' => 'delete'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_a_bucket_shows_its_usage_and_the_scope_of_each_key(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('listBuckets')->andReturn([['name' => 'media', 'created' => null]]);

        $this->getJson('/api/buckets/media')
            ->assertOk()
            ->assertJsonPath('data.objects', 0)
            ->assertJsonPath('data.quotas', ['max_size' => null, 'max_objects' => null])
            ->assertJsonPath('data.keys.0.scope', 'full')
            ->assertJsonPath('data.keys.1.scope', 'read')
            ->assertJsonPath('data.keys.2.scope', 'read-write');
    }

    public function test_provisioning_can_give_the_dedicated_key_a_narrower_scope(): void
    {
        $response = $this->postJson('/api/buckets/provision', ['bucket' => 'tenant-x', 'scope' => 'read'])
            ->assertCreated()
            ->assertJsonPath('scope', 'read');

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'tenant-x', '--key', $response->json('key_name')]);
    }
}
