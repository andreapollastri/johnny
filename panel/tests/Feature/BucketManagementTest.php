<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GarageS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class BucketManagementTest extends TestCase
{
    use FakesJohnnyCli, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    public function test_index_lists_buckets_with_readable_dates(): void
    {
        $this->mock(GarageS3::class)->shouldReceive('listBuckets')->andReturn([
            ['name' => 'default', 'created' => '2026-10-06T22:25:00+00:00'],
            ['name' => 'media', 'created' => '2026-10-06T22:30:00+00:00'],
        ]);

        $this->get('/buckets')
            ->assertOk()
            ->assertSee('media')
            ->assertSee('Oct 6, 2026 22:30')
            ->assertSee('protected');
    }

    public function test_creating_a_bucket_grants_the_system_keys(): void
    {
        $this->post('/buckets', ['name' => 'photos'])
            ->assertRedirect('/buckets/photos')
            ->assertSessionHas('status');

        $this->assertJohnnyRan(['bucket', 'create', 'photos']);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', '--write', '--owner', 'photos', '--key', 'johnny-default']);
        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'photos', '--key', 'johnny-backup']);
    }

    public function test_invalid_bucket_names_never_reach_the_cli(): void
    {
        $this->post('/buckets', ['name' => 'Bad Name'])->assertSessionHasErrors('name');

        $this->assertJohnnyDidntRun(['bucket', 'create']);
    }

    public function test_cli_errors_are_shown_to_the_user(): void
    {
        $this->fakeJohnny(['bucket create' => Process::result('', 'Bucket name already in use', 1)]);

        $this->post('/buckets', ['name' => 'media'])->assertSessionHasErrors(['name' => 'Bucket name already in use']);
    }

    public function test_the_default_bucket_cannot_be_deleted(): void
    {
        $this->delete('/buckets/default')->assertSessionHasErrors('error');

        $this->assertJohnnyDidntRun(['bucket', 'delete']);
    }

    public function test_other_buckets_can_be_deleted(): void
    {
        $this->delete('/buckets/media')->assertRedirect('/buckets');

        $this->assertJohnnyRan(['bucket', 'delete', '--yes', 'media']);
    }

    public function test_access_tab_hides_system_keys(): void
    {
        $this->get('/buckets/media?tab=keys')
            ->assertOk()
            ->assertSee('web-app')
            ->assertSee('analytics-etl')
            ->assertDontSee(self::PANEL_KEY_ID);
    }

    public function test_grant_uses_the_canonical_key_id(): void
    {
        $this->post('/buckets/media/allow', ['key_id' => strtolower(self::WEB_APP_KEY_ID), 'read' => '1'])
            ->assertRedirect('/buckets/media?tab=keys');

        $this->assertJohnnyRan(['bucket', 'allow', '--read', 'media', '--key', self::WEB_APP_KEY_ID]);
    }

    public function test_key_id_prefixes_are_rejected(): void
    {
        // Garage would expand "GK1f85" to johnny-default: the panel must refuse it.
        $this->post('/buckets/media/deny', ['key_id' => 'GK1f85', 'read' => '1', 'write' => '1', 'owner' => '1'])
            ->assertSessionHasErrors(['key_id' => 'Key not found.']);

        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_system_key_permissions_cannot_be_revoked(): void
    {
        $this->post('/buckets/media/deny', ['key_id' => self::PANEL_KEY_ID, 'read' => '1'])
            ->assertSessionHasErrors('key_id');

        $this->assertJohnnyDidntRun(['bucket', 'deny']);
    }

    public function test_grant_requires_at_least_one_permission(): void
    {
        $this->post('/buckets/media/allow', ['key_id' => self::WEB_APP_KEY_ID])->assertSessionHasErrors('key_id');

        $this->assertJohnnyDidntRun(['bucket', 'allow']);
    }
}
