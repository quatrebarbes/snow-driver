<?php

namespace Quatrebarbes\SnowDriver\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Quatrebarbes\SnowDriver\ServiceNowServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ServiceNowServiceProvider::class,
        ];
    }
}
