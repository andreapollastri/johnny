<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Services\Transfers\RcloneCommand;
use PHPUnit\Framework\TestCase;

class RcloneCommandTest extends TestCase
{
    private const LOCAL = ['endpoint' => 'http://127.0.0.1:3900', 'region' => 'johnny', 'key' => 'GKlocal', 'secret' => 'local-secret'];

    private const SPACES = [
        'provider' => 'DigitalOcean', 'endpoint' => 'https://fra1.digitaloceanspaces.com', 'region' => 'us-east-1',
        'key' => 'DO00KEY', 'secret' => 'remote-secret', 'path_style' => true,
    ];

    public function test_an_import_reads_the_provider_and_writes_to_johnny(): void
    {
        $command = RcloneCommand::build('rclone', Transfer::IMPORT, Transfer::COPY, self::LOCAL, 'media', self::SPACES, 'Site-Media');

        $this->assertSame(['rclone', 'copy', 'src:Site-Media', 'dst:media'], array_slice($command['argv'], 0, 4));
        $this->assertContains('--use-json-log', $command['argv']);
        $this->assertContains('--checksum', $command['argv']);
        $this->assertNotContains('--dry-run', $command['argv']);

        $env = $command['env'];
        $this->assertSame('DigitalOcean', $env['RCLONE_CONFIG_SRC_PROVIDER']);
        $this->assertSame('https://fra1.digitaloceanspaces.com', $env['RCLONE_CONFIG_SRC_ENDPOINT']);
        $this->assertSame('remote-secret', $env['RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY']);
        $this->assertSame('true', $env['RCLONE_CONFIG_SRC_NO_CHECK_BUCKET']);
        $this->assertSame('http://127.0.0.1:3900', $env['RCLONE_CONFIG_DST_ENDPOINT']);
        $this->assertSame('GKlocal', $env['RCLONE_CONFIG_DST_ACCESS_KEY_ID']);
        $this->assertSame('true', $env['RCLONE_CONFIG_DST_FORCE_PATH_STYLE']);
        $this->assertSame('true', $env['RCLONE_CONFIG_DST_NO_CHECK_BUCKET']);
    }

    public function test_an_sftp_server_takes_the_place_of_the_other_provider(): void
    {
        $sftp = ['sftp' => ['TYPE' => 'sftp', 'HOST' => 'backup.example.com', 'PORT' => '2222', 'USER' => 'backup', 'PASS' => 'obscured']];

        $out = RcloneCommand::build('rclone', Transfer::EXPORT, Transfer::SYNC, self::LOCAL, 'media', $sftp, 'backups/media', 'photos/');

        $this->assertSame(['rclone', 'sync', 'src:media/photos', 'dst:backups/media/photos'], array_slice($out['argv'], 0, 4));
        $this->assertSame('sftp', $out['env']['RCLONE_CONFIG_DST_TYPE']);
        $this->assertSame('backup.example.com', $out['env']['RCLONE_CONFIG_DST_HOST']);
        $this->assertSame('2222', $out['env']['RCLONE_CONFIG_DST_PORT']);
        $this->assertSame('obscured', $out['env']['RCLONE_CONFIG_DST_PASS']);
        $this->assertArrayNotHasKey('RCLONE_CONFIG_DST_NO_CHECK_BUCKET', $out['env']);
        $this->assertSame('s3', $out['env']['RCLONE_CONFIG_SRC_TYPE']);

        $in = RcloneCommand::build('rclone', Transfer::IMPORT, Transfer::COPY, self::LOCAL, 'media', $sftp, '/srv/data');

        $this->assertSame(['rclone', 'copy', 'src:/srv/data', 'dst:media'], array_slice($in['argv'], 0, 4));
        $this->assertSame('sftp', $in['env']['RCLONE_CONFIG_SRC_TYPE']);
    }

    public function test_secrets_travel_in_the_environment_never_on_the_command_line(): void
    {
        $command = RcloneCommand::build('rclone', Transfer::EXPORT, Transfer::COPY, self::LOCAL, 'media', self::SPACES, 'offsite');
        $line = implode(' ', $command['argv']);

        $this->assertStringNotContainsString('remote-secret', $line);
        $this->assertStringNotContainsString('local-secret', $line);
        $this->assertStringNotContainsString('DO00KEY', $line);
    }

    public function test_an_export_swaps_the_ends_and_can_mirror_a_folder_as_a_dry_run(): void
    {
        $command = RcloneCommand::build(
            binary: 'rclone',
            direction: Transfer::EXPORT,
            mode: Transfer::SYNC,
            local: self::LOCAL,
            localBucket: 'media',
            remote: self::SPACES,
            remoteBucket: 'offsite',
            prefix: 'uploads/2026/',
            dryRun: true,
            createBucket: true,
        );

        $this->assertSame(['rclone', 'sync', 'src:media/uploads/2026', 'dst:offsite/uploads/2026'], array_slice($command['argv'], 0, 4));
        $this->assertContains('--dry-run', $command['argv']);
        $this->assertSame('http://127.0.0.1:3900', $command['env']['RCLONE_CONFIG_SRC_ENDPOINT']);
        $this->assertSame('DO00KEY', $command['env']['RCLONE_CONFIG_DST_ACCESS_KEY_ID']);
        // Asked to create the bucket: let rclone check for it.
        $this->assertSame('false', $command['env']['RCLONE_CONFIG_DST_NO_CHECK_BUCKET']);
    }

    public function test_aws_gets_no_endpoint_and_no_forced_addressing_style(): void
    {
        $aws = ['provider' => 'AWS', 'endpoint' => '', 'region' => 'eu-central-1', 'key' => 'AKIA', 'secret' => 's', 'path_style' => false];

        $env = RcloneCommand::build('rclone', Transfer::IMPORT, Transfer::COPY, self::LOCAL, 'media', $aws, 'bucket')['env'];

        $this->assertArrayNotHasKey('RCLONE_CONFIG_SRC_ENDPOINT', $env);
        $this->assertArrayNotHasKey('RCLONE_CONFIG_SRC_FORCE_PATH_STYLE', $env);
        $this->assertSame('eu-central-1', $env['RCLONE_CONFIG_SRC_REGION']);
    }

    public function test_rclone_runs_without_a_config_file(): void
    {
        $argv = RcloneCommand::build('/usr/bin/rclone', Transfer::IMPORT, Transfer::COPY, self::LOCAL, 'media', self::SPACES, 'b')['argv'];

        $this->assertSame('/usr/bin/rclone', $argv[0]);
        $this->assertSame('', $argv[array_search('--config', $argv, true) + 1]);
    }
}
