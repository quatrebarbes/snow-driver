<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit;

use Quatrebarbes\SnowDriver\Tests\TestCase;

class ServiceNowServiceProviderTest extends TestCase
{
    public function test_it_merges_the_servicenow_config(): void
    {
        $this->assertSame('servicenow', config('servicenow.default'));
        $this->assertArrayHasKey('servicenow', config('servicenow.connections'));
    }
}
