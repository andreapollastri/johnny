<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Johnny's own updates and the server's. The root updater (scripts/johnny-update) keeps its
 * state in a JSON file the panel may read; checking, starting an update and switching the
 * nightly updates on or off go through `sudo -n /usr/local/bin/johnny update …`, as root.
 */
class JohnnyUpdates
{
    private ?bool $managed = null;

    /**
     * The updater runs on this server: its state file is there, or the panel may ask it as root.
     * Otherwise (a local panel, the demo) updates are managed by the installer on the server.
     */
    public function managed(): bool
    {
        if ($this->managed === null) {
            $this->managed = is_file($this->statePath()) || $this->runResult(['status'])->successful();
        }

        return $this->managed;
    }

    /** The VERSION file at the root of the checkout. */
    public function version(): string
    {
        $file = (string) config('services.johnny.updates.version_file');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : 'unknown';
    }

    /**
     * What the updater last wrote: channel, current, previous, latest, main, target,
     * update_available, waiting_reason, checked_at, status, message, started_at,
     * finished_at, auto, log, system{…}.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $path = $this->statePath();

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : [];
    }

    public function running(): bool
    {
        return ($this->state()['status'] ?? null) === 'running';
    }

    /** stable follows the release tags, beta the head of the main branch. */
    public function channel(): string
    {
        return ($this->state()['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    }

    /** Every night at 02:30 (AUTO_UPDATE in /etc/johnny/update.env); on until the updater says otherwise. */
    public function automatic(): bool
    {
        return ($this->state()['auto'] ?? true) !== false;
    }

    /** The build the channel would install, when the last check found one newer than the installed build. */
    public function available(): ?string
    {
        $state = $this->state();
        $target = $state['target'] ?? null;

        return ($state['update_available'] ?? false) === true && is_string($target) && $target !== '' ? $target : null;
    }

    /** Why nothing moves, when the last check said so. */
    public function waiting(): ?string
    {
        $state = $this->state();
        $current = (string) ($state['current'] ?? '');
        $target = (string) ($state['target'] ?? '');

        return match ($state['waiting_reason'] ?? '') {
            'no-release' => 'No release of Johnny is published yet.',
            'behind' => "Johnny runs {$current}, newer than {$target}: it stays there until a newer build of this channel contains it.",
            'diverged' => "{$target} does not contain the installed {$current} (the history differs): Force update installs it anyway.",
            'unreachable' => 'The Johnny repository is not reachable.',
            'missing' => "{$target} is not in the repository.",
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $result = $this->runResult(['check']);
        $decoded = json_decode($result->output(), true);

        if (is_array($decoded) && ($decoded['ok'] ?? false) === false && isset($decoded['error'])) {
            throw new RuntimeException('The check failed: '.$decoded['error'].'.');
        }

        $this->ensureSuccessful($result, 'The check failed.');

        return is_array($decoded) ? $decoded : [];
    }

    /** Start the update as a unit of its own; forced, it installs the channel's newest build even when it is installed. */
    public function start(bool $force = false): void
    {
        $this->run($force ? ['start', '--force'] : ['start'], 'The update could not be started.');
    }

    public function setChannel(string $channel): void
    {
        $this->run(['channel', $channel === 'beta' ? 'beta' : 'stable'], 'The channel could not be changed.');
    }

    public function setAutomatic(bool $on): void
    {
        $this->run(['auto', $on ? 'on' : 'off'], 'Automatic updates could not be changed.');
    }

    /**
     * The last update of the server itself (johnny update system): status, message, started_at,
     * finished_at, changes, reboot_required, reboot_packages, auto, log.
     *
     * @return array<string, mixed>
     */
    public function systemState(): array
    {
        $system = $this->state()['system'] ?? [];

        return is_array($system) ? $system : [];
    }

    public function systemRunning(): bool
    {
        return ($this->systemState()['status'] ?? null) === 'running';
    }

    public function systemAutomatic(): bool
    {
        return ($this->systemState()['auto'] ?? true) !== false;
    }

    /** Every package of the server to its newest version, as a unit of its own. */
    public function startSystem(): void
    {
        $this->run(['system-start'], 'The server update could not be started.');
    }

    public function setSystemAutomatic(bool $on): void
    {
        $this->run(['system-auto', $on ? 'on' : 'off'], 'Nightly server updates could not be changed.');
    }

    /** Where a build can be read: the release page of a tag, the commit of a main build. */
    public function releaseUrl(string $build): string
    {
        $repository = rtrim((string) ($this->state()['repository'] ?? '') ?: (string) config('services.johnny.updates.repository'), '/');

        return str_starts_with($build, 'main-')
            ? $repository.'/commit/'.substr($build, 5)
            : $repository.'/releases/tag/'.$build;
    }

    /**
     * Full argv: `sudo -n /usr/local/bin/johnny update …` (JOHNNY_UPDATE_SUDO=false calls the binary directly).
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function command(array $args): array
    {
        $binary = (string) config('services.johnny.binary', '/usr/local/bin/johnny');
        $prefix = config('services.johnny.updates.sudo', true) ? ['sudo', '-n'] : [];

        return [...$prefix, $binary, 'update', ...$args];
    }

    private function statePath(): string
    {
        return (string) config('services.johnny.updates.state');
    }

    /** @param  list<string>  $args */
    private function runResult(array $args): ProcessResult
    {
        return Process::timeout(120)->run($this->command($args));
    }

    /** @param  list<string>  $args */
    private function run(array $args, string $fallback): void
    {
        $this->ensureSuccessful($this->runResult($args), $fallback);
    }

    private function ensureSuccessful(ProcessResult $result, string $fallback): void
    {
        if ($result->successful()) {
            return;
        }

        $detail = trim($result->errorOutput() ?: $result->output());

        throw new RuntimeException($detail !== '' ? $fallback.' '.$detail : $fallback);
    }
}
