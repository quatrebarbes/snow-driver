<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Eloquent;

use Quatrebarbes\SnowDriver\Tests\Fixtures\CustomTableModel;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

class ServiceNowModelTest extends TestCase
{
    public function test_the_table_name_follows_the_standard_eloquent_convention_by_default(): void
    {
        // EX-105
        $this->assertSame('incidents', (new Incident())->getTable());
    }

    public function test_the_table_property_can_override_the_conventional_table_name(): void
    {
        // EX-105
        $this->assertSame('u_custom_table', (new CustomTableModel())->getTable());
    }

    public function test_sys_id_is_configured_as_a_non_incrementing_string_primary_key(): void
    {
        // EX-106
        $model = new Incident();

        $this->assertSame('sys_id', $model->getKeyName());
        $this->assertFalse($model->getIncrementing());
        $this->assertSame('string', $model->getKeyType());
    }

    public function test_servicenow_timestamp_columns_are_mapped_to_the_eloquent_conventions(): void
    {
        // EX-107
        $model = new Incident();

        $this->assertSame('sys_created_on', $model->getCreatedAtColumn());
        $this->assertSame('sys_updated_on', $model->getUpdatedAtColumn());
    }

    public function test_it_defaults_to_the_servicenow_connection_configured_by_the_package(): void
    {
        $this->app['config']->set('servicenow.default', 'servicenow');

        $this->assertSame('servicenow', (new Incident())->getConnectionName());
    }

    public function test_an_explicit_connection_property_overrides_the_default(): void
    {
        $model = new class extends Incident
        {
            protected $connection = 'servicenow_secondary';
        };

        $this->assertSame('servicenow_secondary', $model->getConnectionName());
    }
}
