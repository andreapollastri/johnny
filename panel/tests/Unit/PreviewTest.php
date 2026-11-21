<?php

namespace Tests\Unit;

use App\Support\Preview;
use PHPUnit\Framework\TestCase;

class PreviewTest extends TestCase
{
    public function test_kinds_come_from_the_extension(): void
    {
        $this->assertSame('image', Preview::kind('photos/Cover.JPG'));
        $this->assertSame('video', Preview::kind('teaser.mp4'));
        $this->assertSame('audio', Preview::kind('jingle.mp3'));
        $this->assertSame('pdf', Preview::kind('docs/programme.pdf'));
        $this->assertSame('text', Preview::kind('notes.md'));
        $this->assertNull(Preview::kind('archive.zip'));
        $this->assertNull(Preview::kind('no-extension'));
    }

    public function test_markup_is_only_ever_served_as_plain_text(): void
    {
        foreach (['page.html', 'drawing.svg', 'script.js'] as $key) {
            $this->assertSame('text', Preview::kind($key));
            $this->assertSame('text/plain; charset=utf-8', Preview::contentType($key));
        }

        $this->assertSame('image/png', Preview::contentType('logo.png'));
        $this->assertNull(Preview::contentType('archive.zip'));
    }
}
