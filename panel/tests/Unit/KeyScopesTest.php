<?php

namespace Tests\Unit;

use App\Support\KeyScopes;
use PHPUnit\Framework\TestCase;

class KeyScopesTest extends TestCase
{
    public function test_every_scope_maps_to_garage_permissions(): void
    {
        $this->assertSame([true, false, false], KeyScopes::flags(KeyScopes::READ));
        $this->assertSame([true, true, false], KeyScopes::flags(KeyScopes::READ_WRITE));
        $this->assertSame([false, true, false], KeyScopes::flags(KeyScopes::WRITE));
        $this->assertSame([true, true, true], KeyScopes::flags(KeyScopes::FULL));
    }

    public function test_an_unknown_scope_falls_back_to_read_only(): void
    {
        $this->assertFalse(KeyScopes::has('delete'));
        $this->assertSame([true, false, false], KeyScopes::flags('delete'));
    }

    public function test_a_permission_list_is_named_by_its_scope(): void
    {
        $this->assertSame(KeyScopes::READ, KeyScopes::fromPermissions('read'));
        $this->assertSame(KeyScopes::READ_WRITE, KeyScopes::fromPermissions('read, write'));
        $this->assertSame(KeyScopes::WRITE, KeyScopes::fromPermissions('write'));
        $this->assertSame(KeyScopes::FULL, KeyScopes::fromPermissions('read, write, owner'));
        $this->assertNull(KeyScopes::fromPermissions('read, owner'));
    }

    public function test_permissions_outside_the_presets_are_shown_as_they_are(): void
    {
        $this->assertSame('Read only', KeyScopes::describe('read'));
        $this->assertSame('Full control', KeyScopes::describe('read, write, owner'));
        $this->assertSame('Custom (read, owner)', KeyScopes::describe('read, owner'));
        $this->assertSame('No access', KeyScopes::describe(''));
    }
}
