<?php

namespace Tests\Feature;

use App\Models\GlobalKey;
use App\Models\User;
use App\Services\GlobalKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

/**
 * Keys for all buckets, keys for chosen buckets, and the scope each one gets.
 */
class KeyScopesTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    /** The key of tests/Fixtures/garage-2.4/key-create.txt. */
    private const NEW_KEY_ID = 'GK9e3b8d1f2a4c6e8b0d2f4a6c';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeJohnny(['key info' => $this->fixture('key-info')]);
    }

    public function test_a_key_can_be_created_for_chosen_buckets_with_a_scope(): void
    {
        $this->post('/keys', ['name' => 'mobile-app', 'access' => 'buckets', 'scope' => 'read', 'buckets' => ['media', 'tenant-acme']])
            ->assertRedirect('/keys')
            ->assertSessionHas('status', 'Key created: read only on media, tenant-acme.');

        $this->assertJohnnyRan(['key', 'create', 'mobile-app']);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'media', '--key', self::NEW_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'tenant-acme', '--key', self::NEW_KEY_ID]);
        $this->assertJohnnyDidntRun(['bucket', 'allow', '--read', 'default']);
        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_a_key_for_chosen_buckets_needs_a_bucket_that_exists(): void
    {
        $this->post('/keys', ['name' => 'mobile-app', 'access' => 'buckets', 'scope' => 'read', 'buckets' => ['nope']])
            ->assertSessionHasErrors('buckets');

        $this->assertJohnnyDidntRun(['key', 'create']);
    }

    public function test_a_global_key_is_granted_on_every_bucket(): void
    {
        $this->post('/keys', ['name' => 'mobile-app', 'access' => 'all', 'scope' => 'read-write'])
            ->assertRedirect('/keys');

        foreach (['default', 'media', 'tenant-acme'] as $bucket) {
            $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', $bucket, '--key', self::NEW_KEY_ID]);
        }

        $this->assertDatabaseHas('global_keys', ['key_id' => self::NEW_KEY_ID, 'name' => 'mobile-app', 'scope' => 'read-write']);
    }

    public function test_the_scope_must_be_one_garage_can_enforce(): void
    {
        $this->post('/keys', ['name' => 'mobile-app', 'access' => 'all', 'scope' => 'delete'])
            ->assertSessionHasErrors('scope');

        $this->assertJohnnyDidntRun(['key', 'create']);
    }

    public function test_a_new_bucket_grants_the_global_keys(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'read']);

        $this->post('/buckets', ['name' => 'photos'])->assertRedirect('/buckets/photos');

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'photos', '--key', self::WEB_APP_KEY_ID]);
    }

    public function test_the_keys_page_marks_global_keys(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'full']);

        $this->get('/keys')
            ->assertOk()
            ->assertSeeInOrder(['All keys', 'analytics-etl', 'Per bucket', 'web-app', 'All buckets', 'Full control']);
    }

    public function test_the_key_page_lists_its_buckets(): void
    {
        $this->get('/keys/'.self::WEB_APP_KEY_ID)
            ->assertOk()
            ->assertSee('web-app')
            ->assertSeeInOrder(['default', 'Write only', 'media', 'Read and write', 'tenant-acme', 'Read only']);

        $this->assertJohnnyRan(['key', 'info', self::WEB_APP_KEY_ID]);
    }

    public function test_system_keys_have_no_page(): void
    {
        $this->get('/keys/'.self::PANEL_KEY_ID)->assertNotFound();
        $this->post('/keys/'.self::PANEL_KEY_ID.'/all-buckets', ['scope' => 'read'])->assertNotFound();
        $this->post('/keys/'.self::PANEL_KEY_ID.'/grant', ['bucket' => 'media', 'scope' => 'none'])->assertNotFound();

        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_a_key_can_be_made_global_with_an_exact_scope(): void
    {
        $this->post('/keys/'.self::WEB_APP_KEY_ID.'/all-buckets', ['scope' => 'read'])
            ->assertRedirect('/keys/'.self::WEB_APP_KEY_ID);

        // Read only everywhere: grant read, take write and owner away.
        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--write', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertDatabaseHas('global_keys', ['key_id' => self::WEB_APP_KEY_ID, 'scope' => 'read']);
    }

    public function test_turning_all_buckets_off_keeps_the_grants(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'read']);

        $this->post('/keys/'.self::WEB_APP_KEY_ID.'/all-buckets', ['scope' => 'off'])
            ->assertRedirect('/keys/'.self::WEB_APP_KEY_ID);

        $this->assertDatabaseCount('global_keys', 0);
        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_access_to_one_bucket_can_be_set_and_revoked_from_the_key_page(): void
    {
        $this->post('/keys/'.self::WEB_APP_KEY_ID.'/grant', ['bucket' => 'media', 'scope' => 'write'])
            ->assertRedirect('/keys/'.self::WEB_APP_KEY_ID)
            ->assertSessionHas('status', 'Write only on media.');

        $this->assertJohnnyRan(['bucket', 'allow', '--write', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--read', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);

        $this->post('/keys/'.self::WEB_APP_KEY_ID.'/grant', ['bucket' => 'media', 'scope' => 'none'])
            ->assertSessionHas('status', 'Access to media revoked.');

        $this->assertJohnnyRan(['bucket', 'deny', '--read', '--write', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);
    }

    public function test_a_grant_needs_a_bucket_that_exists(): void
    {
        $this->post('/keys/'.self::WEB_APP_KEY_ID.'/grant', ['bucket' => 'nope', 'scope' => 'read'])
            ->assertSessionHasErrors('bucket');

        $this->assertJohnnyDidntRun(['bucket', 'allow']);
    }

    public function test_a_key_can_be_created_from_a_bucket_for_that_bucket_only(): void
    {
        $this->followingRedirects()
            ->post('/buckets/media/keys', ['name' => 'mobile-app', 'scope' => 'full'])
            ->assertOk()
            ->assertSee('New key: mobile-app')
            ->assertSee('AWS_BUCKET=media');

        $this->assertJohnnyRan(['key', 'create', 'mobile-app']);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', '--owner', 'media', '--key', self::NEW_KEY_ID]);
        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_granting_a_scope_from_a_bucket_replaces_what_the_key_had(): void
    {
        $this->post('/buckets/media/allow', ['key_id' => self::WEB_APP_KEY_ID, 'scope' => 'read'])
            ->assertRedirect('/buckets/media?tab=keys');

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--write', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);
    }

    public function test_a_scope_cannot_be_set_on_a_system_key(): void
    {
        $this->post('/buckets/media/allow', ['key_id' => self::PANEL_KEY_ID, 'scope' => 'read'])
            ->assertSessionHasErrors('key_id');

        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_deleting_a_key_forgets_that_it_was_global(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'read']);

        $this->delete('/keys', ['key_id' => self::WEB_APP_KEY_ID])->assertRedirect('/keys');

        $this->assertDatabaseCount('global_keys', 0);
    }

    public function test_buckets_created_outside_the_panel_are_picked_up(): void
    {
        GlobalKey::query()->create(['key_id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'scope' => 'read']);
        Cache::forever(GlobalKeys::COVERED, ['default', 'media']);

        $this->artisan('johnny:keys:apply')->assertSuccessful();

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'tenant-acme', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyDidntRun(['bucket', 'allow', '--read', 'media']);
        $this->assertSame(['default', 'media', 'tenant-acme'], Cache::get(GlobalKeys::COVERED));
    }

    public function test_without_global_keys_the_command_does_not_call_garage(): void
    {
        $this->artisan('johnny:keys:apply')->assertSuccessful();

        $this->assertJohnnyDidntRun(['bucket', 'list']);
    }
}
