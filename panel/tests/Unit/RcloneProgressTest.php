<?php

namespace Tests\Unit;

use App\Services\Transfers\RcloneProgress;
use PHPUnit\Framework\TestCase;

class RcloneProgressTest extends TestCase
{
    private const STATS = '{"level":"notice","msg":"\nTransferred:   \t   12.5 MiB / 100 MiB, 12%, 2.5 MiB/s, ETA 35s\n","source":"accounting/stats.go:526","stats":{"bytes":13107200,"checks":3,"deletedDirs":0,"deletes":2,"elapsedTime":5.0,"errors":1,"eta":35,"fatalError":false,"renames":0,"retryError":false,"speed":2621440.7,"totalBytes":104857600,"totalChecks":3,"totalTransfers":40,"transferTime":4.9,"transfers":7},"time":"2026-11-20T03:00:05+00:00"}';

    private const COPIED = '{"level":"info","msg":"Copied (new)","object":"photos/a.jpg","objectType":"*s3.Object","source":"operations/copy.go:368","time":"2026-11-20T03:00:01+00:00"}';

    private const FAILED = '{"level":"error","msg":"Failed to copy: AccessDenied: Access Denied","object":"private/b.bin","source":"operations/copy.go:401","time":"2026-11-20T03:00:02+00:00"}';

    public function test_stats_lines_update_the_counters_and_stay_out_of_the_log(): void
    {
        $progress = new RcloneProgress;
        $progress->feed(self::STATS."\n");

        $this->assertSame(13_107_200, $progress->bytes);
        $this->assertSame(104_857_600, $progress->totalBytes);
        $this->assertSame(7, $progress->transfers);
        $this->assertSame(40, $progress->totalTransfers);
        $this->assertSame(3, $progress->checks);
        $this->assertSame(2, $progress->deletes);
        $this->assertSame(1, $progress->errors);
        $this->assertSame(2_621_441, $progress->speed);
        $this->assertSame(35, $progress->eta);
        $this->assertSame([], $progress->log());
    }

    public function test_other_lines_become_the_log_and_errors_are_remembered(): void
    {
        $progress = new RcloneProgress;
        $progress->feed(self::COPIED."\n".self::FAILED."\n".'{"level":"debug","msg":"noise"}'."\n");

        $this->assertSame(
            ['photos/a.jpg: Copied (new)', 'ERROR private/b.bin: Failed to copy: AccessDenied: Access Denied'],
            $progress->log(),
        );
        $this->assertSame('private/b.bin: Failed to copy: AccessDenied: Access Denied', $progress->lastError);
    }

    public function test_output_may_arrive_in_pieces(): void
    {
        $progress = new RcloneProgress;

        foreach (str_split(self::COPIED."\n".self::STATS."\n", 23) as $piece) {
            $progress->feed($piece);
        }

        $this->assertSame(['photos/a.jpg: Copied (new)'], $progress->log());
        $this->assertSame(7, $progress->transfers);
    }

    public function test_the_last_plain_line_of_a_failure_is_kept_without_its_timestamp(): void
    {
        $progress = new RcloneProgress;
        $progress->feed('2026/11/20 03:00:07 Failed to copy with 2 errors: last error was: AccessDenied');
        $this->assertNull($progress->lastError);

        $progress->finish();

        $this->assertSame('Failed to copy with 2 errors: last error was: AccessDenied', $progress->lastError);
        $this->assertSame(['Failed to copy with 2 errors: last error was: AccessDenied'], $progress->log());
    }

    public function test_the_log_keeps_only_its_tail(): void
    {
        $progress = new RcloneProgress;

        for ($i = 1; $i <= RcloneProgress::LOG_LINES + 25; $i++) {
            $progress->feed('{"level":"info","msg":"Copied (new)","object":"f'.$i.'"}'."\n");
        }

        $this->assertCount(RcloneProgress::LOG_LINES, $progress->log());
        $this->assertSame('f26: Copied (new)', $progress->log()[0]);
    }

    public function test_an_unknown_eta_is_null(): void
    {
        $progress = new RcloneProgress;
        $progress->feed('{"stats":{"bytes":5,"totalBytes":5,"transfers":1,"totalTransfers":1,"eta":null,"speed":0}}'."\n");

        $this->assertNull($progress->eta);
        $this->assertSame(5, $progress->bytes);
    }
}
