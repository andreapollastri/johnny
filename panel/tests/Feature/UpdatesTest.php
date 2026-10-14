<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JohnnyUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private string $statePath;

    private string $versionPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = sys_get_temp_dir().'/johnny-update-test-'.uniqid().'.json';
        $this->versionPath = sys_get_temp_dir().'/johnny-version-test-'.uniqid();
        file_put_contents($this->versionPath, "1.2.0\n");

        config([
            'services.johnny.binary' => '/usr/local/bin/johnny',
            'services.johnny.updates.state' => $this->statePath,
            'services.johnny.updates.sudo' => true,
            'services.johnny.updates.version_file' => $this->versionPath,
            'services.johnny.updates.repository' => 'https://github.com/andreapollastri/johnny',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @unlink($this->versionPath);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $state */
    private function writeState(array $state): void
    {
        file_put_contents($this->statePath, json_encode($state));
    }

    /**
     * Every `sudo -n johnny update …` answers $stdout (or fails with $stderr).
     */
    private function fakeUpdater(string $stdout = '{"ok": true}', int $exitCode = 0, string $stderr = ''): void
    {
        Process::fake(fn () => Process::result($stdout, $stderr, $exitCode));
    }

    /** @param  list<string>  $args */
    private function assertUpdaterRan(array $args): void
    {
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['sudo', '-n', '/usr/local/bin/johnny', 'update', ...$args]);
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/updates')->assertRedirect('/login');
        $this->post('/updates/start')->assertRedirect('/login');
    }

    public function test_without_the_updater_the_page_says_the_installer_manages_updates(): void
    {
        $this->fakeUpdater('', 1, 'sudo: a password is required');
        $this->signIn();

        $this->get('/updates')
            ->assertOk()
            ->assertSee('Updates are managed by the installer on the server')
            ->assertSee('1.2.0')
            ->assertDontSee('Force update');

        $this->post('/updates/start')
            ->assertRedirect('/updates')
            ->assertSessionHasErrors('updates');
        $this->assertUpdaterRan(['status']);
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('start', (array) $p->command, true));
    }

    public function test_the_page_shows_the_installed_build_and_the_update_available(): void
    {
        $this->fakeUpdater();
        $this->writeState([
            'channel' => 'stable',
            'current' => '1.1.0',
            'previous' => '1.0.0',
            'latest' => '1.2.0',
            'main' => 'main-abc1234',
            'target' => '1.2.0',
            'update_available' => true,
            'waiting' => '',
            'waiting_reason' => '',
            'checked_at' => now()->subMinutes(5)->toIso8601String(),
            'auto' => true,
            'status' => 'ok',
            'message' => 'Updated 1.0.0 → 1.1.0.',
            'finished_at' => now()->subDay()->toIso8601String(),
            'log' => ['[2026-10-13 02:30:00] Johnny 1.0.0 → 1.1.0 (stable channel)'],
            'system' => ['auto' => false, 'status' => 'ok', 'message' => 'Updated 3 packages.', 'finished_at' => now()->subDay()->toIso8601String(),
                'changes' => ['caddy 2.8.4 → 2.9.1'], 'reboot_required' => true, 'reboot_packages' => ['linux-image-6.8.0-45-generic']],
        ]);
        $this->signIn();

        $this->get('/updates')
            ->assertOk()
            ->assertSee('Update to 1.2.0')
            ->assertSee('https://github.com/andreapollastri/johnny/releases/tag/1.2.0', false)
            ->assertSee('update available')
            ->assertSee('before: 1.0.0')
            ->assertSee('Updated 1.0.0 → 1.1.0.')
            ->assertSee('Stable: Johnny installs the release tags')
            ->assertSee('reboot required')
            ->assertSee('linux-image-6.8.0-45-generic')
            ->assertSee('caddy 2.8.4 → 2.9.1')
            ->assertSee('only when started here or on the server')
            ->assertDontSee('window.location.reload()', false);

        Process::assertNothingRan();
    }

    public function test_the_page_says_why_nothing_moves(): void
    {
        $this->fakeUpdater();
        $this->writeState([
            'channel' => 'stable', 'current' => 'main-1a2b3c4', 'latest' => '1.2.0', 'target' => '1.2.0',
            'update_available' => false, 'waiting_reason' => 'behind',
        ]);
        $this->signIn();

        $this->get('/updates')
            ->assertOk()
            ->assertSee('Johnny runs main-1a2b3c4, newer than 1.2.0')
            ->assertDontSee('Update to')
            ->assertSee('https://github.com/andreapollastri/johnny/commit/1a2b3c4', false);
    }

    public function test_a_running_update_is_followed(): void
    {
        $this->fakeUpdater();
        $this->writeState([
            'channel' => 'beta', 'current' => '1.2.0', 'target' => 'main-abc1234', 'main' => 'main-abc1234',
            'status' => 'running', 'message' => 'Updating 1.2.0 → main-abc1234', 'log' => ['[x] fetching main'],
        ]);
        $this->signIn();

        $this->get('/updates')
            ->assertOk()
            ->assertSee('Updating 1.2.0 → main-abc1234')
            ->assertSee('fetching main')
            ->assertSee('Newest on main')
            ->assertSee('window.location.reload()', false);

        $this->getJson('/updates/status')
            ->assertOk()
            ->assertJson([
                'running' => true,
                'message' => 'Updating 1.2.0 → main-abc1234',
                'log' => ['[x] fetching main'],
                'system' => ['running' => false],
            ]);
    }

    public function test_check_now_runs_the_check_as_root(): void
    {
        $this->writeState(['channel' => 'stable']);
        $this->fakeUpdater('{"ok":true,"update_available":false}');
        $this->signIn();

        $this->post('/updates/check')
            ->assertRedirect('/updates')
            ->assertSessionHas('status', 'Checked for updates.');

        $this->assertUpdaterRan(['check']);
    }

    public function test_an_unreachable_repository_is_reported(): void
    {
        $this->writeState(['channel' => 'stable']);
        $this->fakeUpdater('{"ok":false,"error":"the Johnny repository is not reachable"}', 1);
        $this->signIn();

        $this->post('/updates/check')->assertSessionHasErrors(['updates' => 'The check failed: the Johnny repository is not reachable.']);
    }

    public function test_update_and_force_update_start_the_updater(): void
    {
        $this->writeState(['channel' => 'stable']);
        $this->fakeUpdater('{"ok":true,"unit":"johnny-update-1"}');
        $this->signIn();

        $this->post('/updates/start')->assertSessionHas('status', 'Update started.');
        $this->assertUpdaterRan(['start']);

        $this->post('/updates/start', ['force' => '1'])->assertSessionHas('status', 'Forced update started.');
        $this->assertUpdaterRan(['start', '--force']);
    }

    public function test_a_refused_start_is_shown(): void
    {
        $this->writeState(['channel' => 'stable']);
        $this->fakeUpdater('', 1, 'another update is running');
        $this->signIn();

        $this->post('/updates/start')
            ->assertSessionHasErrors(['updates' => 'The update could not be started. another update is running']);
    }

    public function test_channel_and_automatic_updates(): void
    {
        $this->writeState(['channel' => 'stable']);
        $this->fakeUpdater();
        $this->signIn();

        $this->post('/updates/channel', ['channel' => 'beta'])->assertSessionHas('status');
        $this->assertUpdaterRan(['channel', 'beta']);

        $this->post('/updates/channel', ['channel' => 'nightly'])->assertSessionHasErrors('channel');
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('nightly', (array) $p->command, true));

        $this->post('/updates/auto', ['enabled' => '0'])->assertSessionHas('status', 'Automatic updates are off.');
        $this->assertUpdaterRan(['auto', 'off']);

        $this->post('/updates/auto', ['enabled' => '1'])->assertSessionHas('status', 'Automatic updates are on.');
        $this->assertUpdaterRan(['auto', 'on']);
    }

    public function test_the_server_update_and_its_nightly_switch(): void
    {
        $this->writeState(['channel' => 'stable', 'system' => ['auto' => true]]);
        $this->fakeUpdater();
        $this->signIn();

        $this->post('/updates/system/start')->assertSessionHas('status', 'Server update started.');
        $this->assertUpdaterRan(['system-start']);

        $this->post('/updates/system/auto', ['enabled' => '0'])->assertSessionHas('status', 'Nightly server updates are off.');
        $this->assertUpdaterRan(['system-auto', 'off']);
    }

    public function test_the_service_reads_the_state(): void
    {
        $updates = app(JohnnyUpdates::class);
        $this->assertSame([], $updates->state());
        $this->assertSame('stable', $updates->channel());
        $this->assertTrue($updates->automatic());
        $this->assertNull($updates->available());
        $this->assertSame('1.2.0', $updates->version());

        $this->writeState([
            'channel' => 'beta', 'auto' => false, 'target' => 'main-abc1234', 'update_available' => true,
            'repository' => 'https://github.com/someone/johnny-fork',
            'system' => ['status' => 'running', 'auto' => false],
        ]);
        $this->assertSame('beta', $updates->channel());
        $this->assertFalse($updates->automatic());
        $this->assertSame('main-abc1234', $updates->available());
        $this->assertTrue($updates->systemRunning());
        $this->assertFalse($updates->systemAutomatic());
        $this->assertSame('https://github.com/someone/johnny-fork/commit/abc1234', $updates->releaseUrl('main-abc1234'));
        $this->assertSame('https://github.com/someone/johnny-fork/releases/tag/1.2.0', $updates->releaseUrl('1.2.0'));

        file_put_contents($this->statePath, 'not json');
        $this->assertSame([], $updates->state());
    }

    public function test_waiting_reasons(): void
    {
        $updates = app(JohnnyUpdates::class);

        $cases = [
            'no-release' => 'No release of Johnny is published yet.',
            'diverged' => '1.2.0 does not contain the installed main-1a2b3c4 (the history differs): Force update installs it anyway.',
            'unreachable' => 'The Johnny repository is not reachable.',
            '' => null,
        ];
        foreach ($cases as $reason => $message) {
            $this->writeState(['current' => 'main-1a2b3c4', 'target' => '1.2.0', 'waiting_reason' => $reason]);
            $this->assertSame($message, $updates->waiting(), "reason: {$reason}");
        }
    }

    public function test_the_nav_marks_an_update_available(): void
    {
        $this->fakeUpdater();
        $this->writeState(['target' => '1.2.0', 'update_available' => true]);
        $this->signIn();

        $this->get('/settings')->assertOk()->assertSee('Updates — 1.2.0 is available')->assertSee('nav-dot', false);
    }
}
