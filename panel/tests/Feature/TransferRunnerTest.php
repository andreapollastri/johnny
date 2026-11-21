<?php

namespace Tests\Feature;

use App\Models\Transfer;
use App\Services\Transfers\TransferRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\MakesTransfers;
use Tests\TestCase;

/**
 * The process that runs one transfer: rclone is faked, its JSON log is real.
 */
class TransferRunnerTest extends TestCase
{
    use MakesTransfers, RefreshDatabase;

    private const STATS = '{"level":"notice","msg":"stats","stats":{"bytes":4096,"totalBytes":4096,"transfers":2,"totalTransfers":2,"checks":5,"deletes":0,"errors":0,"speed":2048,"eta":0},"time":"2026-11-20T03:00:02+00:00"}';

    private const COPIED = '{"level":"info","msg":"Copied (new)","object":"photos/a.jpg","time":"2026-11-20T03:00:01+00:00"}';

    private const FAILED = '{"level":"error","msg":"Failed to copy: AccessDenied: Access Denied","object":"private/b.bin","time":"2026-11-20T03:00:02+00:00"}';

    private function runner(): TransferRunner
    {
        $runner = app(TransferRunner::class);
        $runner->tick = 0;
        $runner->saveEvery = 0.0;

        return $runner;
    }

    private function isRclone(PendingProcess $process): bool
    {
        return is_array($process->command) && $process->command[0] === config('services.johnny.transfers.rclone');
    }

    public function test_a_transfer_runs_rclone_and_records_what_it_copied(): void
    {
        Process::fake([
            '*' => Process::describe()->errorOutput(self::COPIED)->errorOutput(self::STATS)->exitCode(0)->iterations(2),
        ]);
        $transfer = $this->makeTransfer($this->makeConnection());

        $this->runner()->run($transfer);

        $transfer->refresh();
        $this->assertSame(Transfer::COMPLETED, $transfer->status);
        $this->assertSame(4096, $transfer->bytes_done);
        $this->assertSame(2, $transfer->files_done);
        $this->assertSame('Copied 2 files (4.00 KB).', $transfer->message);
        $this->assertSame(['photos/a.jpg: Copied (new)'], $transfer->log);
        $this->assertNotNull($transfer->finished_at);

        Process::assertRan(fn (PendingProcess $process) => $this->isRclone($process)
            && in_array('copy', $process->command, true)
            && in_array('src:cernusco-media', $process->command, true)
            && in_array('dst:media', $process->command, true)
            && ($process->environment['RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY'] ?? null) === 'remote-secret');
    }

    public function test_a_failed_transfer_keeps_the_last_error(): void
    {
        Process::fake([
            '*' => Process::describe()->errorOutput(self::FAILED)->exitCode(1),
        ]);
        $transfer = $this->makeTransfer($this->makeConnection());

        $this->runner()->run($transfer);

        $transfer->refresh();
        $this->assertSame(Transfer::FAILED, $transfer->status);
        $this->assertStringContainsString('AccessDenied', (string) $transfer->message);
    }

    public function test_a_missing_rclone_is_named_as_the_cause(): void
    {
        Process::fake(['*' => Process::describe()->exitCode(127)]);
        $transfer = $this->makeTransfer($this->makeConnection());

        $this->runner()->run($transfer);

        $this->assertStringContainsString('rclone was not found', (string) $transfer->refresh()->message);
    }

    public function test_a_cancelled_transfer_stops_rclone(): void
    {
        Process::fake([
            '*' => Process::describe()->errorOutput(self::COPIED)->exitCode(0)->iterations(3),
        ]);
        $transfer = $this->makeTransfer($this->makeConnection(), ['cancel_requested_at' => now()]);

        $this->runner()->run($transfer);

        $transfer->refresh();
        $this->assertSame(Transfer::CANCELLED, $transfer->status);
        $this->assertStringStartsWith('Cancelled.', (string) $transfer->message);
    }

    public function test_only_a_waiting_transfer_is_run(): void
    {
        Process::fake();
        $transfer = $this->makeTransfer($this->makeConnection(), ['status' => Transfer::COMPLETED]);

        $this->runner()->run($transfer);

        Process::assertDidntRun(fn (PendingProcess $process) => $this->isRclone($process));
        $this->assertSame(Transfer::COMPLETED, $transfer->refresh()->status);
    }

    public function test_a_transfer_without_its_connection_fails_cleanly(): void
    {
        Process::fake();
        $connection = $this->makeConnection();
        $transfer = $this->makeTransfer($connection);
        $connection->delete();

        $this->runner()->run($transfer->refresh());

        $this->assertSame(Transfer::FAILED, $transfer->refresh()->status);
        $this->assertSame('The connection this transfer used was deleted.', $transfer->message);
    }

    public function test_the_next_waiting_transfer_starts_when_one_ends(): void
    {
        Process::fake();
        $connection = $this->makeConnection();
        $first = $this->makeTransfer($connection);
        $second = $this->makeTransfer($connection);

        $this->runner()->run($first);

        $this->assertNotNull($second->refresh()->launched_at);
    }

    public function test_the_command_runs_one_transfer_by_id(): void
    {
        Process::fake();
        $transfer = $this->makeTransfer($this->makeConnection());

        $this->artisan('johnny:transfer', ['transfer' => $transfer->id])->assertSuccessful();
        $this->assertSame(Transfer::COMPLETED, $transfer->refresh()->status);

        $this->artisan('johnny:transfer', ['transfer' => $transfer->id])->assertSuccessful();
        $this->artisan('johnny:transfer', ['transfer' => 999])->assertFailed();
    }
}
