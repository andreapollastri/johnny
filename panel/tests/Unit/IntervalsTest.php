<?php

namespace Tests\Unit;

use App\Support\Intervals;
use PHPUnit\Framework\TestCase;

class IntervalsTest extends TestCase
{
    public function test_presets_are_read_as_minutes(): void
    {
        $this->assertSame(1, Intervals::fromInput('1'));
        $this->assertSame(60, Intervals::fromInput('60'));
        $this->assertSame(10080, Intervals::fromInput(10080));
    }

    public function test_a_custom_interval_is_a_number_and_a_unit(): void
    {
        $this->assertSame(7, Intervals::fromInput('custom', '7', 'minutes'));
        $this->assertSame(180, Intervals::fromInput('custom', 3, 'hours'));
        $this->assertSame(2880, Intervals::fromInput('custom', '2', 'days'));
    }

    public function test_invalid_intervals_are_refused(): void
    {
        $this->assertNull(Intervals::fromInput('7'));
        $this->assertNull(Intervals::fromInput(null));
        $this->assertNull(Intervals::fromInput('custom', '0', 'minutes'));
        $this->assertNull(Intervals::fromInput('custom', '1.5', 'hours'));
        $this->assertNull(Intervals::fromInput('custom', '5', 'weeks'));
        $this->assertNull(Intervals::fromInput('custom', '400', 'days'));
    }

    public function test_intervals_are_named_for_people(): void
    {
        $this->assertSame('Every minute', Intervals::label(1));
        $this->assertSame('Every day', Intervals::label(1440));
        $this->assertSame('Every 90 minutes', Intervals::label(90));
        $this->assertSame('Every 3 hours', Intervals::label(180));
        $this->assertSame('Every 2 days', Intervals::label(2880));
    }

    public function test_an_interval_fills_the_form_back(): void
    {
        $this->assertSame('15', Intervals::preset(15));
        $this->assertSame('custom', Intervals::preset(90));
        $this->assertSame([90, 'minutes'], Intervals::split(90));
        $this->assertSame([3, 'hours'], Intervals::split(180));
    }
}
