<?php

namespace App\Services\Transfers;

/**
 * Reads what rclone writes with `--use-json-log --stats …`: one JSON object per line. The lines
 * that carry a `stats` object update the counters; the others (a file copied, an error) are kept
 * as the tail of a log. Output arrives in arbitrary chunks, so a partial line waits for its end.
 */
class RcloneProgress
{
    /** Log lines kept; older ones are dropped. */
    public const LOG_LINES = 200;

    public int $bytes = 0;

    public int $totalBytes = 0;

    public int $transfers = 0;

    public int $totalTransfers = 0;

    public int $checks = 0;

    public int $deletes = 0;

    public int $errors = 0;

    public int $speed = 0;

    public ?int $eta = null;

    public ?string $lastError = null;

    private string $buffer = '';

    /** @var list<string> */
    private array $log = [];

    public function feed(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $lines = explode("\n", $this->buffer.$chunk);
        $this->buffer = (string) array_pop($lines);

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    /**
     * The process ended: whatever is left in the buffer is its last line.
     */
    public function finish(): void
    {
        $rest = $this->buffer;
        $this->buffer = '';
        $this->line($rest);
    }

    /**
     * @return list<string>
     */
    public function log(): array
    {
        return $this->log;
    }

    private function line(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $entry = $line[0] === '{' ? json_decode($line, true) : null;

        if (! is_array($entry)) {
            // Not JSON: rclone's last words on a failure ("Failed to copy with 2 errors: …"), or a usage error.
            if (preg_match('/\b(failed|error|fatal)\b/i', $line)) {
                $this->lastError = $this->withoutTimestamp($line);
            }
            $this->remember($this->withoutTimestamp($line));

            return;
        }

        if (isset($entry['stats']) && is_array($entry['stats'])) {
            $this->stats($entry['stats']);

            return;
        }

        $level = strtolower((string) ($entry['level'] ?? 'info'));
        if ($level === 'debug') {
            return;
        }

        $message = trim((string) ($entry['msg'] ?? ''));
        if (isset($entry['object']) && is_string($entry['object']) && $entry['object'] !== '') {
            $message = $entry['object'].': '.$message;
        }
        if ($message === '') {
            return;
        }

        if (in_array($level, ['error', 'critical', 'fatal', 'emergency', 'alert'], true)) {
            $this->lastError = $message;
            $message = 'ERROR '.$message;
        }

        $this->remember($message);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function stats(array $stats): void
    {
        $this->bytes = (int) ($stats['bytes'] ?? $this->bytes);
        $this->totalBytes = (int) ($stats['totalBytes'] ?? $this->totalBytes);
        $this->transfers = (int) ($stats['transfers'] ?? $this->transfers);
        $this->totalTransfers = (int) ($stats['totalTransfers'] ?? $this->totalTransfers);
        $this->checks = (int) ($stats['checks'] ?? $this->checks);
        $this->deletes = (int) ($stats['deletes'] ?? $this->deletes);
        $this->errors = (int) ($stats['errors'] ?? $this->errors);
        $this->speed = (int) round((float) ($stats['speed'] ?? 0));
        $this->eta = isset($stats['eta']) && is_numeric($stats['eta']) ? (int) $stats['eta'] : null;

        if (isset($stats['lastError']) && is_string($stats['lastError']) && $stats['lastError'] !== '') {
            $this->lastError = $stats['lastError'];
        }
    }

    private function remember(string $message): void
    {
        $this->log[] = mb_strimwidth($message, 0, 500, '…');

        if (count($this->log) > self::LOG_LINES) {
            $this->log = array_slice($this->log, -self::LOG_LINES);
        }
    }

    /**
     * "2026/11/20 03:00:01 ERROR : …" → "ERROR : …".
     */
    private function withoutTimestamp(string $line): string
    {
        return (string) preg_replace('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}\s+/', '', $line);
    }
}
