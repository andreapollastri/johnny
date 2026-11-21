<?php

namespace Tests\Unit;

use App\Support\Format;
use Tests\TestCase;

class FormatTest extends TestCase
{
    public function test_bytes_uses_binary_units(): void
    {
        $this->assertSame('0 B', Format::bytes(0));
        $this->assertSame('512 B', Format::bytes(512));
        $this->assertSame('1.50 KB', Format::bytes(1536));
        $this->assertSame('1.06 GB', Format::bytes(1_138_166_333));
    }

    public function test_date_is_human_readable_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Europe/Rome']);

        $this->assertSame('Oct 7, 2026 00:25', Format::date('2026-10-06T22:25:00+00:00'));
        $this->assertSame('Oct 7, 2026', Format::date('2026-10-07', withTime: false));
        $this->assertSame('—', Format::date(null));
    }

    public function test_duration_is_short_and_readable(): void
    {
        $this->assertSame('12s', Format::duration(12));
        $this->assertSame('1m 35s', Format::duration(95));
        $this->assertSame('1h 2m', Format::duration(3725));
        $this->assertSame('0s', Format::duration(-4));
    }

    public function test_parse_bytes_reads_the_sizes_garage_and_php_print(): void
    {
        $this->assertSame(0, Format::parseBytes('0 B'));
        $this->assertSame(1_048_576, Format::parseBytes('1048576'));
        $this->assertSame(2 * 1024 ** 2, Format::parseBytes('2M'));
        $this->assertSame(10 * 1024 ** 3, Format::parseBytes('10.0 GiB'));
        $this->assertSame(535_717_478, Format::parseBytes('510.9 MiB'));
        $this->assertNull(Format::parseBytes('plenty'));
    }
}
