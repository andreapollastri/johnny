<?php

namespace App\Services;

use App\Models\RemoteConnection;
use App\Services\Transfers\RcloneCommand;
use Illuminate\Support\Facades\Process;

/**
 * Connections to a server reached over SSH (SFTP). rclone does the talking, as it does for
 * transfers; the server's host keys are pinned the first time it answers, so a different server
 * at the same address is refused afterwards.
 */
class RemoteSftp
{
    /**
     * Try the connection and record the outcome on it. Returns the error, or null.
     */
    public function check(RemoteConnection $connection): ?string
    {
        $error = null;

        try {
            $this->pinHostKey($connection);
            $this->listFolders($connection);
        } catch (\Throwable $e) {
            $error = $this->describe($e);
        }

        $connection->forceFill(['checked_at' => now(), 'check_error' => $error])->save();

        return $error;
    }

    /**
     * The folders of the directory the user lands in: what a transfer can be pointed at.
     *
     * @return list<array{name: string, created: ?string}>
     */
    public function listFolders(RemoteConnection $connection): array
    {
        $binary = (string) config('services.johnny.transfers.rclone', 'rclone');

        $result = Process::timeout(40)
            ->env(RcloneCommand::sftpEnvironment('SRC', $connection))
            ->run([$binary, 'lsjson', 'src:', '--dirs-only', '--config', '', '--contimeout', '10s', '--timeout', '20s', '--retries', '1', '--low-level-retries', '1']);

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'rclone stopped with exit code '.$result->exitCode().'.');
        }

        $folders = [];

        foreach ((array) json_decode($result->output(), true) as $entry) {
            if (is_array($entry) && isset($entry['Name']) && ! str_starts_with((string) $entry['Name'], '.')) {
                $folders[] = ['name' => (string) $entry['Name'], 'created' => isset($entry['ModTime']) ? (string) $entry['ModTime'] : null];
            }
        }

        usort($folders, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $folders;
    }

    /**
     * Remember the server's host keys (trust on first use). Nothing changes once they are pinned:
     * to accept a server that was reinstalled, the connection is saved again.
     */
    public function pinHostKey(RemoteConnection $connection): void
    {
        if ($connection->host_key !== null) {
            return;
        }

        $result = Process::timeout(20)->run([
            'ssh-keyscan', '-T', '8', '-p', (string) $connection->sftpPort(), $connection->sftpHost(),
        ]);

        $keys = array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#'),
        ));

        if (! $result->successful() || $keys === []) {
            throw new \RuntimeException("The server did not answer over SSH on {$connection->sftpHost()}:{$connection->sftpPort()}.");
        }

        $connection->forceFill(['host_key' => implode("\n", $keys)])->save();
    }

    /**
     * A short reason a person can act on, out of rclone's log lines.
     */
    public function describe(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'unable to authenticate'), str_contains($message, 'no supported methods remain') => 'The server refused the user name, the password or the key.',
            str_contains($message, 'knownhosts: key mismatch'), str_contains($message, 'host key mismatch') => 'The server answers with a different host key than the one pinned. If it was reinstalled, save the connection again.',
            str_contains($message, 'connection refused'), str_contains($message, 'i/o timeout'), str_contains($message, 'no such host') => 'The server did not answer. Check the host and the port.',
            str_contains($message, 'executable file not found'), str_contains($message, 'No such file or directory') && str_contains($message, 'rclone') => 'rclone was not found on this server.',
            default => mb_strimwidth(trim(strtok($message, "\n") ?: $message), 0, 300, '…'),
        };
    }
}
