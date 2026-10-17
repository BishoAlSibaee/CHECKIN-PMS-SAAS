<?php

namespace Tests\Unit\Services;

use App\Services\PropertyPermissionBundle;
use PHPUnit\Framework\TestCase;

class PropertyPermissionBundleTest extends TestCase
{
    public function test_expand_adds_view_permissions_when_property_trigger_present(): void
    {
        $input = ['manage rooms'];
        $expanded = PropertyPermissionBundle::expand($input);

        $this->assertContains('view buildings', $expanded);
        $this->assertContains('view floors', $expanded);
        $this->assertContains('view suites', $expanded);
        $this->assertContains('view rooms', $expanded);
        $this->assertContains('view room types', $expanded);
        $this->assertContains('manage rooms', $expanded);
    }

    public function test_expand_unchanged_when_no_property_trigger(): void
    {
        $input = ['view clients', 'manage clients'];
        $expanded = PropertyPermissionBundle::expand($input);

        $this->assertEqualsCanonicalizing($input, $expanded);
    }

    public function test_view_permissions_constant_is_complete(): void
    {
        $this->assertCount(5, PropertyPermissionBundle::VIEW_PERMISSIONS);
    }
}
