<?php

namespace Tests\Feature\Api;

use App\Models\Sync;
use App\Models\Transfer;
use App\Models\User;
use App\Services\RemoteS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesJohnnyCli;
use Tests\Concerns\MakesTransfers;
use Tests\TestCase;

/**
 * Connections, transfers and scheduled syncs over the API.
 */
class TransfersApiTest extends TestCase
{
    use FakesJohnnyCli, MakesTransfers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    public function test_the_api_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/connections')->assertUnauthorized();
        $this->postJson('/api/transfers', [])->assertUnauthorized();
        $this->getJson('/api/syncs')->assertUnauthorized();
    }

    // --- Connections --------------------------------------------------------------

    public function test_providers_are_listed(): void
    {
        $this->getJson('/api/providers')
            ->assertOk()
            ->assertJsonCount(11, 'data')
            ->assertJsonPath('data.0.id', 'aws')
            ->assertJsonPath('data.0.needs_endpoint', false)
            ->assertJsonPath('data.10.id', 'sftp');
    }

    public function test_connections_never_show_their_secret(): void
    {
        $this->makeConnection();

        $this->getJson('/api/connections')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Spaces production')
            ->assertJsonPath('data.0.provider_label', 'DigitalOcean Spaces')
            ->assertJsonPath('data.0.access_key_id', 'DO00…EKEY')
            ->assertJsonPath('data.0.reachable', null)
            ->assertJsonMissingPath('data.0.secret_access_key')
            ->assertDontSee('remote-secret');
    }

    public function test_a_connection_is_saved_and_checked(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->once()->andReturnNull();

        $this->postJson('/api/connections', [
            'name' => 'R2 archive',
            'provider' => 'r2',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'region' => 'auto',
            'access_key_id' => 'R2KEY',
            'secret_access_key' => 'r2-secret',
        ])->assertCreated()->assertJsonPath('data.name', 'R2 archive')->assertDontSee('r2-secret');

        $this->assertDatabaseHas('remote_connections', ['name' => 'R2 archive', 'provider' => 'r2']);
    }

    public function test_an_sftp_server_is_saved_as_a_connection(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->once()->andReturnNull();

        $this->postJson('/api/connections', [
            'name' => 'NAS',
            'provider' => 'sftp',
            'host' => 'Backup.Example.com',
            'port' => 2222,
            'username' => 'backup',
            'password' => 'sftp-password',
        ])
            ->assertCreated()
            ->assertJsonPath('data.provider_label', 'SFTP server')
            ->assertJsonPath('data.endpoint', 'sftp://backup.example.com:2222')
            ->assertJsonPath('data.access_key_id', 'backup')
            ->assertJsonPath('data.host_key_pinned', false)
            ->assertDontSee('sftp-password');

        $this->postJson('/api/connections', ['name' => 'NAS 2', 'provider' => 'sftp', 'host' => 'sftp://nas/path', 'username' => 'backup'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('host');
    }

    public function test_a_connection_needs_what_its_provider_needs(): void
    {
        $this->postJson('/api/connections', ['name' => 'R2 archive', 'provider' => 'r2', 'access_key_id' => 'R2KEY', 'secret_access_key' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('endpoint');

        $this->assertDatabaseCount('remote_connections', 0);
    }

    public function test_updating_a_connection_without_a_secret_keeps_the_stored_one(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->andReturnNull();
        $connection = $this->makeConnection();

        $this->putJson("/api/connections/{$connection->id}", [
            'name' => 'Spaces renamed',
            'provider' => 'spaces',
            'endpoint' => 'https://ams3.digitaloceanspaces.com',
            'access_key_id' => 'DO00EXAMPLEKEY',
        ])->assertOk()->assertJsonPath('data.name', 'Spaces renamed');

        $this->assertSame('remote-secret', $connection->refresh()->secret_access_key);
    }

    public function test_a_connection_can_be_checked_and_its_buckets_listed(): void
    {
        $connection = $this->makeConnection();
        $remote = $this->mock(RemoteS3::class);
        $remote->shouldReceive('check')->andReturn('The provider refused the credentials.');
        $remote->shouldReceive('listBuckets')->andReturn([['name' => 'cernusco-media', 'created' => null]]);

        $this->postJson("/api/connections/{$connection->id}/check")
            ->assertOk()
            ->assertJsonPath('data', ['id' => $connection->id, 'reachable' => false, 'error' => 'The provider refused the credentials.']);

        $this->getJson("/api/connections/{$connection->id}/buckets")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'cernusco-media');
    }

    public function test_a_connection_in_use_is_not_deleted(): void
    {
        $connection = $this->makeConnection();
        $this->makeSync($connection, ['next_run_at' => now()->addHour()]);

        $this->deleteJson("/api/connections/{$connection->id}")->assertConflict();
        $this->assertDatabaseCount('remote_connections', 1);

        Sync::query()->delete();

        $this->deleteJson("/api/connections/{$connection->id}")->assertNoContent();
        $this->getJson("/api/connections/{$connection->id}")->assertNotFound();
    }

    // --- Transfers ----------------------------------------------------------------

    public function test_an_import_is_queued_and_its_bucket_created(): void
    {
        $connection = $this->makeConnection();

        $this->postJson('/api/transfers', [
            'direction' => 'import',
            'connection_id' => $connection->id,
            'remote_bucket' => 'Legacy_Uploads',
            'local_bucket' => 'photos',
            'prefix' => 'uploads/2026',
            'mode' => 'copy',
            'dry_run' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.direction', 'import')
            ->assertJsonPath('data.prefix', 'uploads/2026/')
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.sync_id', null);

        $this->assertJohnnyRan(['bucket', 'create', 'photos']);
        $this->assertNotNull(Transfer::query()->sole()->launched_at);
    }

    public function test_an_export_needs_a_bucket_that_exists_here(): void
    {
        $connection = $this->makeConnection();

        $this->postJson('/api/transfers', [
            'direction' => 'export',
            'connection_id' => $connection->id,
            'remote_bucket' => 'offsite',
            'local_bucket' => 'photos',
            'mode' => 'copy',
        ])->assertUnprocessable()->assertJsonValidationErrors('local_bucket');

        $this->postJson('/api/transfers', ['direction' => 'sideways', 'connection_id' => 999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['direction', 'connection_id', 'remote_bucket', 'local_bucket', 'mode']);

        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_transfers_are_listed_without_the_runs_of_syncs(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['next_run_at' => now()->addHour()]);
        $this->makeTransfer($connection, ['status' => Transfer::COMPLETED]);
        $this->makeTransfer($connection, ['status' => Transfer::FAILED]);
        $run = $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::COMPLETED]);

        $this->getJson('/api/transfers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/api/transfers?status=completed')->assertJsonCount(1, 'data');
        $this->getJson("/api/transfers?sync_id={$sync->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $run->id);
        $this->getJson('/api/transfers?status=sleeping')->assertUnprocessable();
    }

    public function test_a_transfer_shows_its_progress_and_log(): void
    {
        $transfer = $this->makeTransfer($this->makeConnection(), [
            'status' => Transfer::RUNNING,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'bytes_done' => 1024,
            'bytes_total' => 4096,
            'log' => ['photos/a.jpg: Copied (new)'],
        ]);

        $this->getJson("/api/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.percent', 25)
            ->assertJsonPath('data.bytes_done', 1024)
            ->assertJsonPath('data.log', ['photos/a.jpg: Copied (new)']);

        $this->getJson('/api/transfers/999')->assertNotFound();
    }

    public function test_a_transfer_can_be_cancelled_until_it_ends(): void
    {
        $connection = $this->makeConnection();
        $waiting = $this->makeTransfer($connection);
        $running = $this->makeTransfer($connection, ['status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);
        $done = $this->makeTransfer($connection, ['status' => Transfer::COMPLETED]);

        $this->postJson("/api/transfers/{$waiting->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/transfers/{$running->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.cancelling', true);
        $this->postJson("/api/transfers/{$done->id}/cancel")->assertConflict();
    }

    public function test_a_dry_run_can_be_run_for_real(): void
    {
        $connection = $this->makeConnection();
        $dry = $this->makeTransfer($connection, ['status' => Transfer::COMPLETED, 'dry_run' => true]);
        $running = $this->makeTransfer($connection, ['status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->postJson("/api/transfers/{$dry->id}/retry", ['for_real' => true])
            ->assertCreated()
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.status', 'pending');

        $this->postJson("/api/transfers/{$running->id}/retry")->assertConflict();
    }

    public function test_only_finished_transfers_are_removed(): void
    {
        $connection = $this->makeConnection();
        $done = $this->makeTransfer($connection, ['status' => Transfer::FAILED]);
        $running = $this->makeTransfer($connection, ['status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->deleteJson("/api/transfers/{$done->id}")->assertNoContent();
        $this->deleteJson("/api/transfers/{$running->id}")->assertConflict();

        $this->assertDatabaseCount('transfers', 1);
    }

    // --- Scheduled syncs -----------------------------------------------------------

    public function test_a_sync_is_created_with_an_interval_in_minutes(): void
    {
        $connection = $this->makeConnection();

        $this->postJson('/api/syncs', [
            'direction' => 'export',
            'connection_id' => $connection->id,
            'remote_bucket' => 'media-offsite',
            'local_bucket' => 'media',
            'mode' => 'sync',
            'interval_minutes' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.interval_minutes', 1)
            ->assertJsonPath('data.interval_label', 'Every minute')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.mode', 'sync')
            ->assertJsonCount(1, 'data.runs')
            ->assertJsonPath('data.runs.0.status', 'pending')
            ->assertJsonPath('data.latest_run.status', 'pending');

        $this->assertDatabaseHas('syncs', ['local_bucket' => 'media', 'interval_minutes' => 1]);
    }

    public function test_a_sync_needs_a_real_interval_and_a_real_bucket(): void
    {
        $connection = $this->makeConnection();
        $fields = ['direction' => 'export', 'connection_id' => $connection->id, 'remote_bucket' => 'offsite', 'local_bucket' => 'media', 'mode' => 'copy'];

        $this->postJson('/api/syncs', $fields)->assertUnprocessable()->assertJsonValidationErrors('interval_minutes');
        $this->postJson('/api/syncs', $fields + ['interval_minutes' => 0])->assertUnprocessable()->assertJsonValidationErrors('interval_minutes');
        $this->postJson('/api/syncs', ['local_bucket' => 'nope', 'interval_minutes' => 5] + $fields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('local_bucket');

        $this->assertDatabaseCount('syncs', 0);
    }

    public function test_syncs_are_listed_with_the_state_of_the_scheduler(): void
    {
        $this->makeSync($this->makeConnection(), ['next_run_at' => now()->addHour()]);

        $this->getJson('/api/syncs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.connection_name', 'Spaces production')
            ->assertJsonPath('data.0.latest_run', null)
            ->assertJsonPath('scheduler_running', false);
    }

    public function test_a_sync_can_be_changed_paused_and_resumed(): void
    {
        $sync = $this->makeSync($this->makeConnection(), ['next_run_at' => now()->addHour()]);

        $this->patchJson("/api/syncs/{$sync->id}", ['interval_minutes' => 90, 'mode' => 'sync', 'enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.interval_minutes', 90)
            ->assertJsonPath('data.interval_label', 'Every 90 minutes')
            ->assertJsonPath('data.mode', 'sync')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonCount(0, 'data.runs');

        // Resuming runs it right away.
        $this->putJson("/api/syncs/{$sync->id}", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(1, 'data.runs');

        $this->patchJson("/api/syncs/{$sync->id}", ['interval_minutes' => 0])->assertUnprocessable();
    }

    public function test_a_sync_can_be_run_now_but_not_twice_at_once(): void
    {
        $sync = $this->makeSync($this->makeConnection(), ['next_run_at' => now()->addHour()]);

        $this->postJson("/api/syncs/{$sync->id}/run")->assertCreated()->assertJsonPath('data.sync_id', $sync->id);
        $this->postJson("/api/syncs/{$sync->id}/run")->assertConflict();

        $this->getJson("/api/syncs/{$sync->id}")->assertOk()->assertJsonCount(1, 'data.runs');
    }

    public function test_a_sync_is_deleted_with_its_runs_once_none_is_running(): void
    {
        $connection = $this->makeConnection();
        $sync = $this->makeSync($connection, ['next_run_at' => now()->addHour()]);
        $run = $this->makeTransfer($connection, ['sync_id' => $sync->id, 'status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->deleteJson("/api/syncs/{$sync->id}")->assertConflict();

        $run->forceFill(['status' => Transfer::COMPLETED])->save();

        $this->deleteJson("/api/syncs/{$sync->id}")->assertNoContent();
        $this->assertDatabaseCount('syncs', 0);
        $this->assertDatabaseCount('transfers', 0);
    }
}
