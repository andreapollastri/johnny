<?php

namespace Tests\Feature;

use App\Models\RemoteConnection;
use App\Models\Transfer;
use App\Models\User;
use App\Services\GarageS3;
use App\Services\RemoteS3;
use App\Services\Transfers\TransferRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\FakesJohnnyCli;
use Tests\Concerns\MakesTransfers;
use Tests\TestCase;

/**
 * SFTP servers as the other side of transfers and syncs. ssh-keyscan and rclone are faked.
 */
class SftpConnectionsTest extends TestCase
{
    use FakesJohnnyCli, MakesTransfers, RefreshDatabase;

    private const HOST_KEY = 'backup.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleHostKeyForTestsOnly';

    private const PRIVATE_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\nEXAMPLEONLY\n-----END OPENSSH PRIVATE KEY-----";

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->fakeJohnny();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/ssh'));

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSftp(array $attributes = []): RemoteConnection
    {
        return $this->makeConnection($attributes + [
            'name' => 'NAS',
            'provider' => 'sftp',
            'endpoint' => 'sftp://backup.example.com:2222',
            'region' => null,
            'access_key_id' => 'backup',
            'secret_access_key' => 'sftp-password',
            'path_style' => false,
        ]);
    }

    /**
     * ssh-keyscan, `rclone obscure` and `rclone lsjson`, answering like the real ones.
     */
    private function fakeSsh(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;

            return match (true) {
                $command[0] === 'ssh-keyscan' => Process::result(self::HOST_KEY."\n", '# backup.example.com:2222 SSH-2.0-OpenSSH_9.6'),
                in_array('obscure', $command, true) => Process::result("OBSCURED\n"),
                in_array('lsjson', $command, true) => Process::result('[{"Path":"media","Name":"media","IsDir":true,"ModTime":"2026-11-01T10:00:00Z"},{"Path":".ssh","Name":".ssh","IsDir":true},{"Path":"Archive","Name":"Archive","IsDir":true}]'),
                default => Process::result(),
            };
        });
    }

    public function test_an_sftp_server_is_saved_with_its_password_encrypted(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->once()->andReturnNull();

        $this->post('/connections', [
            'name' => 'NAS',
            'provider' => 'sftp',
            'host' => 'Backup.Example.com',
            'port' => '2222',
            'username' => 'backup',
            'password' => 'sftp-password',
        ])->assertRedirect('/connections/1');

        $connection = RemoteConnection::query()->sole();
        $this->assertTrue($connection->isSftp());
        $this->assertSame('sftp://backup.example.com:2222', $connection->endpoint);
        $this->assertSame('backup.example.com', $connection->sftpHost());
        $this->assertSame(2222, $connection->sftpPort());
        $this->assertSame('backup', $connection->access_key_id);
        $this->assertSame('sftp-password', $connection->secret_access_key);
        $this->assertFalse($connection->usesPrivateKey());
        $this->assertDatabaseMissing('remote_connections', ['secret_access_key' => 'sftp-password']);
    }

    public function test_a_private_key_can_be_used_instead_of_a_password(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->andReturnNull();

        $this->post('/connections', ['name' => 'NAS', 'provider' => 'sftp', 'host' => 'backup.example.com', 'username' => 'backup', 'private_key' => self::PRIVATE_KEY])
            ->assertRedirect('/connections/1');

        $connection = RemoteConnection::query()->sole();
        $this->assertTrue($connection->usesPrivateKey());
        $this->assertSame(22, $connection->sftpPort());
    }

    public function test_an_sftp_server_needs_a_host_a_user_and_a_secret(): void
    {
        $fields = ['name' => 'NAS', 'provider' => 'sftp', 'host' => 'backup.example.com', 'username' => 'backup'];

        $this->post('/connections', $fields)->assertSessionHasErrors('password');
        $this->post('/connections', ['host' => 'sftp://backup.example.com/data', 'password' => 'x'] + $fields)->assertSessionHasErrors('host');
        $this->post('/connections', ['username' => '', 'password' => 'x'] + $fields)->assertSessionHasErrors('username');
        $this->post('/connections', ['private_key' => 'not a key'] + $fields)->assertSessionHasErrors('private_key');

        $this->assertDatabaseCount('remote_connections', 0);
    }

    public function test_saving_again_keeps_the_password_and_forgets_the_pinned_host_key(): void
    {
        $this->mock(RemoteS3::class)->shouldReceive('check')->andReturnNull();
        $connection = $this->makeSftp(['host_key' => self::HOST_KEY]);

        $this->put("/connections/{$connection->id}", ['name' => 'NAS', 'provider' => 'sftp', 'host' => 'backup.example.com', 'port' => '22', 'username' => 'backup'])
            ->assertRedirect("/connections/{$connection->id}");

        $connection->refresh();
        $this->assertSame('sftp://backup.example.com:22', $connection->endpoint);
        $this->assertSame('sftp-password', $connection->secret_access_key);
        $this->assertNull($connection->host_key);
    }

    public function test_the_first_check_pins_the_host_key_and_lists_the_folders(): void
    {
        $this->fakeSsh();
        $connection = $this->makeSftp();

        $this->post("/connections/{$connection->id}/check")->assertSessionHas('status', 'NAS answers.');

        $connection->refresh();
        $this->assertSame(self::HOST_KEY, $connection->host_key);
        $this->assertTrue($connection->reachable());

        Process::assertRan(fn (PendingProcess $process) => (array) $process->command === ['ssh-keyscan', '-T', '8', '-p', '2222', 'backup.example.com']);
        Process::assertRan(fn (PendingProcess $process) => in_array('lsjson', (array) $process->command, true)
            && $process->environment['RCLONE_CONFIG_SRC_TYPE'] === 'sftp'
            && $process->environment['RCLONE_CONFIG_SRC_HOST'] === 'backup.example.com'
            && $process->environment['RCLONE_CONFIG_SRC_PORT'] === '2222'
            && $process->environment['RCLONE_CONFIG_SRC_USER'] === 'backup'
            && $process->environment['RCLONE_CONFIG_SRC_PASS'] === 'OBSCURED'
            && ! in_array('sftp-password', (array) $process->command, true));

        $this->assertSame(self::HOST_KEY."\n", File::get(storage_path("app/private/ssh/known_hosts-{$connection->id}")));
    }

    public function test_the_connection_page_lists_folders_to_import(): void
    {
        $this->fakeSsh();
        $this->mock(GarageS3::class)->shouldReceive('listBuckets')->andReturn([['name' => 'media', 'created' => null]]);
        $connection = $this->makeSftp(['host_key' => self::HOST_KEY]);

        $this->get("/connections/{$connection->id}")
            ->assertOk()
            ->assertSee('Folders on the server')
            ->assertSeeInOrder(['Archive', 'media'])
            ->assertDontSee('.ssh')
            ->assertDontSee('sftp-password');
    }

    public function test_a_server_that_does_not_answer_is_reported(): void
    {
        Process::fake(fn () => Process::result('', 'connection refused', 1));
        $connection = $this->makeSftp();

        $this->post("/connections/{$connection->id}/check")->assertSessionHasErrors('connection');

        $connection->refresh();
        $this->assertFalse($connection->reachable());
        $this->assertNull($connection->host_key);
        $this->assertStringContainsString('did not answer over SSH on backup.example.com:2222', (string) $connection->check_error);
    }

    public function test_a_transfer_can_write_to_a_folder_of_the_server(): void
    {
        $connection = $this->makeSftp();

        $this->post('/transfers', [
            'direction' => 'export',
            'connection_id' => $connection->id,
            'remote_bucket' => 'backups//media/',
            'local_bucket' => 'media',
            'mode' => 'copy',
        ])->assertRedirect('/transfers/1');

        $this->assertDatabaseHas('transfers', ['provider' => 'sftp', 'remote_bucket' => 'backups/media', 'local_bucket' => 'media']);
    }

    public function test_a_folder_cannot_climb_out_and_a_bucket_is_not_a_path(): void
    {
        $sftp = $this->makeSftp();
        $s3 = $this->makeConnection();
        $fields = ['direction' => 'export', 'local_bucket' => 'media', 'mode' => 'copy'];

        $this->post('/transfers', $fields + ['connection_id' => $sftp->id, 'remote_bucket' => 'backups/../../etc'])->assertSessionHasErrors('remote_bucket');
        $this->post('/transfers', $fields + ['connection_id' => $sftp->id, 'remote_bucket' => '/'])->assertSessionHasErrors('remote_bucket');
        $this->post('/transfers', $fields + ['connection_id' => $s3->id, 'remote_bucket' => 'backups/media'])->assertSessionHasErrors('remote_bucket');

        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_a_sync_can_keep_a_folder_of_the_server_up_to_date(): void
    {
        $connection = $this->makeSftp();

        $this->post('/transfers', [
            'direction' => 'export',
            'connection_id' => $connection->id,
            'remote_bucket' => '/srv/backups/media',
            'local_bucket' => 'media',
            'mode' => 'sync',
            'schedule' => 'repeat',
            'interval' => '1',
        ])->assertRedirect('/syncs/1');

        $this->assertDatabaseHas('syncs', ['remote_bucket' => '/srv/backups/media', 'interval_minutes' => 1, 'mode' => 'sync']);
        $this->get('/syncs/1')->assertOk()->assertSee('NAS: /srv/backups/media')->assertSee('SFTP server');
    }

    public function test_rclone_gets_the_private_key_on_one_line_and_the_pinned_host_key(): void
    {
        Process::fake();
        $connection = $this->makeSftp(['secret_access_key' => self::PRIVATE_KEY, 'host_key' => self::HOST_KEY]);
        $transfer = $this->makeTransfer($connection, ['direction' => Transfer::IMPORT, 'remote_bucket' => '/srv/data', 'provider' => 'sftp']);

        $runner = app(TransferRunner::class);
        $runner->tick = 0;
        $runner->run($transfer);

        $this->assertSame(Transfer::COMPLETED, $transfer->refresh()->status);

        Process::assertRan(fn (PendingProcess $process) => in_array('src:/srv/data', (array) $process->command, true)
            && $process->environment['RCLONE_CONFIG_SRC_TYPE'] === 'sftp'
            && $process->environment['RCLONE_CONFIG_SRC_KEY_PEM'] === str_replace("\n", '\n', self::PRIVATE_KEY)
            && ! isset($process->environment['RCLONE_CONFIG_SRC_PASS'])
            && $process->environment['RCLONE_CONFIG_SRC_KNOWN_HOSTS_FILE'] === storage_path("app/private/ssh/known_hosts-{$connection->id}")
            && $process->environment['RCLONE_CONFIG_DST_TYPE'] === 's3');
    }
}
