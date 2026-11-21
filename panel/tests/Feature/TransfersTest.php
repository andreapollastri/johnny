<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Models\User;
use App\Services\GarageS3;
use App\Services\RemoteS3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\FakesJohnnyCli;
use Tests\Concerns\MakesTransfers;
use Tests\TestCase;

/**
 * Connections to other providers, and the imports and exports that use them. rclone itself
 * never runs here: starting a transfer is a faked process (see TransferRunnerTest).
 */
class TransfersTest extends TestCase
{
    use FakesJohnnyCli, MakesTransfers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    public function test_the_page_lists_connections_and_past_transfers(): void
    {
        $connection = $this->makeConnection();
        $this->makeTransfer($connection, ['status' => Transfer::COMPLETED, 'bytes_done' => 2048, 'bytes_total' => 2048]);

        $this->get('/transfers')
            ->assertOk()
            ->assertSee('Spaces production')
            ->assertSee('DigitalOcean Spaces')
            ->assertSee('Spaces production: cernusco-media')
            ->assertSee('completed');
    }

    public function test_the_page_explains_the_first_steps_without_connections(): void
    {
        $this->get('/transfers')->assertOk()->assertSee('Add a connection')->assertDontSee('History');
    }

    public function test_a_connection_is_saved_with_its_secret_encrypted(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->once()->andReturnNull();

        $this->post('/connections', [
            'name' => 'R2 archive',
            'provider' => 'r2',
            'endpoint' => 'https://account.r2.cloudflarestorage.com/',
            'region' => 'auto',
            'access_key_id' => 'R2KEY',
            'secret_access_key' => 'r2-secret',
        ])->assertRedirect('/connections/1')->assertSessionHas('status', 'Connected to R2 archive.');

        $row = DB::table('remote_connections')->first();
        $this->assertSame('https://account.r2.cloudflarestorage.com', $row->endpoint);
        $this->assertStringNotContainsString('r2-secret', $row->secret_access_key);
        $this->assertStringNotContainsString('R2KEY', $row->access_key_id);
    }

    public function test_a_connection_that_does_not_answer_is_saved_with_the_reason(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->andReturn('The access key ID is not known to the provider.');

        $this->post('/connections', [
            'name' => 'AWS legacy',
            'provider' => 'aws',
            'region' => 'eu-central-1',
            'access_key_id' => 'AKIAEXAMPLE',
            'secret_access_key' => 'secret',
        ])->assertRedirect('/connections/1')->assertSessionHasErrors('connection');

        $this->assertDatabaseHas('remote_connections', ['name' => 'AWS legacy', 'provider' => 'aws']);
    }

    public function test_providers_other_than_aws_need_an_endpoint(): void
    {
        $this->post('/connections', [
            'name' => 'R2 archive',
            'provider' => 'r2',
            'access_key_id' => 'R2KEY',
            'secret_access_key' => 'r2-secret',
        ])->assertSessionHasErrors('endpoint');

        $this->assertDatabaseCount('remote_connections', 0);
    }

    public function test_an_empty_secret_keeps_the_stored_one(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->andReturnNull();
        $connection = $this->makeConnection();

        $this->put("/connections/{$connection->id}", [
            'name' => 'Spaces renamed',
            'provider' => 'spaces',
            'endpoint' => 'https://ams3.digitaloceanspaces.com',
            'access_key_id' => 'DO00EXAMPLEKEY',
            'secret_access_key' => '',
        ])->assertRedirect("/connections/{$connection->id}");

        $connection->refresh();
        $this->assertSame('Spaces renamed', $connection->name);
        $this->assertSame('remote-secret', $connection->secret_access_key);
    }

    public function test_the_connection_page_lists_the_buckets_of_the_provider(): void
    {
        $connection = $this->makeConnection();
        $this->mock(RemoteS3::class)->shouldReceive('listBuckets')->andReturn([
            ['name' => 'cernusco-media', 'created' => '2024-03-02T10:00:00+00:00'],
        ]);
        $this->mock(GarageS3::class)->shouldReceive('listBuckets')->andReturn([['name' => 'media', 'created' => null]]);

        $this->get("/connections/{$connection->id}")
            ->assertOk()
            ->assertSee('cernusco-media')
            ->assertDontSee('remote-secret');
    }

    public function test_the_form_offers_a_one_off_transfer_or_a_sync(): void
    {
        $this->makeConnection();
        $this->mock(RemoteS3::class)->shouldReceive('listBuckets')->andReturn([['name' => 'cernusco-media', 'created' => null]]);
        $this->mock(GarageS3::class)->shouldReceive('listBuckets')->andReturn([['name' => 'media', 'created' => null]]);

        $this->get('/transfers/new')
            ->assertOk()
            ->assertSee('Once, now')
            ->assertSee('Keep in sync')
            ->assertSee('Every minute')
            ->assertSee('Another interval');
    }

    public function test_the_form_needs_a_connection(): void
    {
        $this->get('/transfers/new')->assertRedirect('/transfers')->assertSessionHasErrors('connection');
    }

    public function test_an_import_creates_the_bucket_and_starts_in_the_background(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', [
            'direction' => 'import',
            'connection_id' => $connection->id,
            'remote_bucket' => 'Legacy_Uploads',
            'local_bucket' => 'photos',
            'prefix' => '/2026/',
            'mode' => 'copy',
        ])->assertRedirect('/transfers/1')->assertSessionHas('status', 'Import started.');

        $this->assertJohnnyRan(['bucket', 'create', 'photos']);
        $this->assertDatabaseHas('transfers', [
            'direction' => 'import',
            'local_bucket' => 'photos',
            'remote_bucket' => 'Legacy_Uploads',
            'prefix' => '2026/',
            'connection_name' => 'Spaces production',
            'status' => 'pending',
            'sync_id' => null,
        ]);
        $this->assertNotNull(Transfer::query()->first()->launched_at);
        Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
            && $process->command[0] === 'sh'
            && str_contains($process->command[2], 'johnny:transfer 1'));
    }

    public function test_an_export_needs_a_bucket_that_exists_here(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', [
            'direction' => 'export',
            'connection_id' => $connection->id,
            'remote_bucket' => 'offsite',
            'local_bucket' => 'photos',
            'mode' => 'copy',
        ])->assertSessionHasErrors('local_bucket');

        $this->assertJohnnyDidntRun(['bucket', 'create']);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_an_export_can_mirror_and_create_the_remote_bucket(): void
    {
        $connection = $this->makeConnection();

        $this->post('/transfers', [
            'direction' => 'export',
            'connection_id' => $connection->id,
            'remote_bucket' => 'offsite',
            'local_bucket' => 'media',
            'mode' => 'sync',
            'dry_run' => '1',
            'create_bucket' => '1',
        ])->assertRedirect('/transfers/1')->assertSessionHas('status', 'Export started.');

        $this->assertDatabaseHas('transfers', ['direction' => 'export', 'mode' => 'sync', 'dry_run' => true, 'create_bucket' => true]);
    }

    public function test_several_buckets_can_be_imported_at_once(): void
    {
        $connection = $this->makeConnection();

        $this->post("/connections/{$connection->id}/import", ['buckets' => ['Photos', 'Bad_Name']])
            ->assertRedirect('/transfers')
            ->assertSessionHas('status', '1 import queued.')
            ->assertSessionHasErrors('buckets');

        $this->assertJohnnyRan(['bucket', 'create', 'photos']);
        $this->assertDatabaseHas('transfers', ['remote_bucket' => 'Photos', 'local_bucket' => 'photos', 'direction' => 'import']);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_only_a_few_transfers_run_at_a_time(): void
    {
        config(['services.johnny.transfers.concurrency' => 2]);
        $connection = $this->makeConnection();
        $this->makeTransfer($connection);
        $this->makeTransfer($connection);
        $this->makeTransfer($connection);

        $this->getJson('/transfers/status')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonCount(3, 'transfers');

        $this->assertSame(2, Transfer::query()->whereNotNull('launched_at')->count());
    }

    public function test_a_transfer_whose_process_died_is_marked_failed(): void
    {
        $connection = $this->makeConnection();
        $transfer = $this->makeTransfer($connection, [
            'status' => Transfer::RUNNING,
            'started_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinutes(9),
        ]);

        $this->get('/transfers')->assertOk();

        $this->assertSame(Transfer::FAILED, $transfer->refresh()->status);
    }

    public function test_the_transfer_page_follows_a_running_transfer(): void
    {
        $connection = $this->makeConnection();
        $transfer = $this->makeTransfer($connection, [
            'status' => Transfer::RUNNING,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'bytes_done' => 1024,
            'bytes_total' => 4096,
            'log' => ['photos/a.jpg: Copied (new)'],
        ]);

        $this->get("/transfers/{$transfer->id}")
            ->assertOk()
            ->assertSee('Spaces production: cernusco-media')
            ->assertSee('photos/a.jpg: Copied (new)');

        $this->getJson("/transfers/{$transfer->id}/status")
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('active', true)
            ->assertJsonPath('percent', 25.0)
            ->assertJsonPath('log.0', 'photos/a.jpg: Copied (new)');
    }

    public function test_a_waiting_transfer_is_cancelled_at_once_and_a_running_one_is_asked_to_stop(): void
    {
        $connection = $this->makeConnection();
        $waiting = $this->makeTransfer($connection);
        $running = $this->makeTransfer($connection, ['status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->post("/transfers/{$waiting->id}/cancel")->assertRedirect("/transfers/{$waiting->id}");
        $this->post("/transfers/{$running->id}/cancel")->assertRedirect("/transfers/{$running->id}");

        $this->assertSame(Transfer::CANCELLED, $waiting->refresh()->status);
        $this->assertSame(Transfer::RUNNING, $running->refresh()->status);
        $this->assertNotNull($running->cancel_requested_at);
    }

    public function test_a_dry_run_can_be_run_for_real(): void
    {
        $connection = $this->makeConnection();
        $dry = $this->makeTransfer($connection, ['status' => Transfer::COMPLETED, 'dry_run' => true, 'mode' => Transfer::SYNC]);

        $this->post("/transfers/{$dry->id}/retry", ['for_real' => '1'])->assertRedirect('/transfers/2');

        $this->assertDatabaseHas('transfers', ['id' => 2, 'dry_run' => false, 'mode' => 'sync', 'status' => 'pending']);
    }

    public function test_a_running_transfer_cannot_be_started_again_or_removed(): void
    {
        $connection = $this->makeConnection();
        $running = $this->makeTransfer($connection, ['status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->post("/transfers/{$running->id}/retry")->assertSessionHasErrors('transfer');
        $this->delete("/transfers/{$running->id}")->assertSessionHasErrors('transfer');

        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_a_finished_transfer_can_be_removed_from_the_history(): void
    {
        $connection = $this->makeConnection();
        $done = $this->makeTransfer($connection, ['status' => Transfer::FAILED]);

        $this->delete("/transfers/{$done->id}")->assertRedirect('/transfers');

        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_a_connection_in_use_cannot_be_deleted(): void
    {
        $connection = $this->makeConnection();
        $this->makeTransfer($connection, ['status' => Transfer::RUNNING, 'started_at' => now(), 'heartbeat_at' => now()]);

        $this->delete("/connections/{$connection->id}")->assertSessionHasErrors('connection');

        $this->assertDatabaseCount('remote_connections', 1);
    }

    public function test_deleting_a_connection_keeps_its_history(): void
    {
        $connection = $this->makeConnection();
        $done = $this->makeTransfer($connection, ['status' => Transfer::COMPLETED]);

        $this->delete("/connections/{$connection->id}")->assertRedirect('/transfers');

        $this->assertDatabaseCount('remote_connections', 0);
        $this->assertNull($done->refresh()->remote_connection_id);
        $this->get("/transfers/{$done->id}")->assertOk()->assertSee('Spaces production');
    }

    public function test_transfers_need_a_signed_in_user(): void
    {
        auth()->logout();

        $this->get('/transfers')->assertRedirect('/login');
        $this->post('/transfers', [])->assertRedirect('/login');
    }
}
