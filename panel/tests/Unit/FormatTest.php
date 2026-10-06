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
}
