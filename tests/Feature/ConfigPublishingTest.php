<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Quatrebarbes\SnowDriver\Tests\TestCase;

class ConfigPublishingTest extends TestCase
{
    public function test_the_servicenow_config_can_be_published(): void
    {
        $published = config_path('servicenow.php');

        if (file_exists($published)) {
            unlink($published);
        }

        $this->artisan('vendor:publish', [
            '--provider' => \Quatrebarbes\SnowDriver\ServiceNowServiceProvider::class,
            '--tag' => 'servicenow-config',
        ])->run();

        $this->assertFileExists($published);

        unlink($published);
    }
}
