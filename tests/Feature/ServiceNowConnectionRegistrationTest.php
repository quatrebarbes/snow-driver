<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Tests\TestCase;

class ServiceNowConnectionRegistrationTest extends TestCase
{
    public function test_the_servicenow_driver_is_registered_into_database_connections(): void
    {
        // EX-101 : la connexion est déclarée au niveau de config/database.php,
        // alimentée par défaut depuis config/servicenow.php (SNOW_*).
        $this->assertArrayHasKey('servicenow', config('database.connections'));
        $this->assertSame('servicenow', config('database.connections.servicenow.driver'));
    }

    public function test_it_resolves_to_a_service_now_connection_instance(): void
    {
        config(['database.connections.servicenow.base_url' => 'https://dev12345.service-now.com']);

        $connection = DB::connection('servicenow');

        $this->assertInstanceOf(ServiceNowConnection::class, $connection);
    }

    public function test_resolving_the_connection_does_not_eagerly_validate_configuration(): void
    {
        // EX-121 : aucune exception avant la première requête, même sans base_url.
        config(['database.connections.servicenow.base_url' => null]);

        $connection = DB::connection('servicenow');

        $this->assertInstanceOf(ServiceNowConnection::class, $connection);
    }
}
