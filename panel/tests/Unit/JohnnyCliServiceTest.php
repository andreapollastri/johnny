<?php

namespace Tests\Unit;

use App\Services\JohnnyCliService;
use Tests\Concerns\FakesJohnnyCli;
use Tests\TestCase;

class JohnnyCliServiceTest extends TestCase
{
    use FakesJohnnyCli;

    private JohnnyCliService $cli;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cli = new JohnnyCliService;
    }

    public function test_command_runs_the_binary_through_sudo_by_default(): void
    {
        $this->assertSame(
            ['sudo', '-n', '-u', 'johnny', '/usr/local/bin/johnny', 'bucket', 'list'],
            $this->cli->command(['bucket', 'list']),
        );
    }

    public function test_command_skips_sudo_when_no_user_is_configured(): void
    {
        config(['services.johnny.binary' => '/opt/demo/johnny', 'services.johnny.sudo_user' => '']);

        $this->assertSame(['/opt/demo/johnny', 'key', 'list'], $this->cli->command(['key', 'list']));
    }

    public function test_it_parses_garage_2_key_list(): void
    {
        $keys = $this->cli->parseKeyList($this->fixture('key-list'));

        $this->assertCount(4, $keys);
        $this->assertSame(
            ['id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'created' => '2026-10-07'],
            $keys[2],
        );
    }

    public function test_it_parses_legacy_key_list_without_dates(): void
    {
        $keys = $this->cli->parseKeyList("Key name     Key ID\nGKabc123  my-key\n");

        $this->assertSame([['id' => 'GKabc123', 'name' => 'my-key', 'created' => null]], $keys);
    }

    public function test_user_keys_hides_system_keys_and_sorts_by_name(): void
    {
        $keys = $this->cli->userKeys($this->cli->parseKeyList($this->fixture('key-list')));

        $this->assertSame(['analytics-etl', 'web-app'], array_column($keys, 'name'));
    }

    public function test_it_parses_garage_2_bucket_list(): void
    {
        $this->assertSame(
            ['default', 'media', 'tenant-acme'],
            $this->cli->parseGarageBucketList($this->fixture('bucket-list')),
        );
    }

    public function test_it_parses_keys_and_permissions_from_bucket_info(): void
    {
        $keys = $this->cli->parseBucketInfoKeys($this->fixture('bucket-info'));

        $this->assertSame([
            ['id' => self::PANEL_KEY_ID, 'name' => 'johnny-default', 'permissions' => 'read, write, owner'],
            ['id' => 'GK7c1b2d3e4f5a6b7c8d9e0f1a', 'name' => 'johnny-backup', 'permissions' => 'read'],
            ['id' => self::WEB_APP_KEY_ID, 'name' => 'web-app', 'permissions' => 'read, write'],
        ], $keys);
    }

    public function test_it_extracts_credentials_from_key_create_output(): void
    {
        $this->assertSame([
            'access_key_id' => 'GK9e3b8d1f2a4c6e8b0d2f4a6c',
            'secret_access_key' => '3f1c9a7e5b2d8f4a6c0e2b4d6f8a1c3e5b7d9f0a2c4e6b8d0f1a3c5e7b9d2f4a',
        ], $this->cli->parseKeyCreateOutput($this->fixture('key-create')));

        $this->assertNull($this->cli->parseKeyCreateOutput('Error: something went wrong'));
    }

    public function test_canonical_key_id_requires_an_exact_match(): void
    {
        $this->fakeJohnny();

        $this->assertSame(self::WEB_APP_KEY_ID, $this->cli->resolveCanonicalKeyId(strtolower(self::WEB_APP_KEY_ID)));
        // Garage itself would resolve this prefix to johnny-default; the panel must not.
        $this->assertNull($this->cli->resolveCanonicalKeyId('GK1f85'));
    }

    public function test_system_keys_are_detected_by_name_and_by_panel_key_id(): void
    {
        $this->fakeJohnny();

        $this->assertTrue($this->cli->isSystemKey(self::PANEL_KEY_ID));
        $this->assertTrue($this->cli->isSystemKey('GK7c1b2d3e4f5a6b7c8d9e0f1a'));
        $this->assertFalse($this->cli->isSystemKey(self::WEB_APP_KEY_ID));
    }

    public function test_permission_commands_pass_only_requested_flags(): void
    {
        $this->fakeJohnny();

        $this->cli->bucketAllow('media', self::WEB_APP_KEY_ID, true, false, true);
        $this->cli->bucketDeny('media', self::WEB_APP_KEY_ID, false, true, false);

        $this->assertJohnnyRan(['bucket', 'allow', '--read', '--owner', 'media', '--key', self::WEB_APP_KEY_ID]);
        $this->assertJohnnyRan(['bucket', 'deny', '--write', 'media', '--key', self::WEB_APP_KEY_ID]);
    }
}
