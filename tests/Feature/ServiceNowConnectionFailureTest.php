<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;
use Quatrebarbes\SnowDriver\Tests\TestCase;

class ServiceNowConnectionFailureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.servicenow.base_url', 'https://dev12345.service-now.com');
        $app['config']->set('database.connections.servicenow.auth', [
            'mode' => 'basic',
            'username' => 'alice',
            'password' => 'secret',
        ]);
    }

    public function test_an_unreachable_instance_throws_a_dedicated_exception_on_first_request(): void
    {
        // EX-126 : instance injoignable/timeout -> exception dédiée, à la
        // première requête effective (EX-121), pas avant.
        Http::fake(Http::failedConnection());

        $connection = DB::connection('servicenow');

        $this->expectException(ServiceNowConnectionException::class);

        $connection->connect();
    }

    public function test_credentials_are_attached_to_the_connectivity_check_request(): void
    {
        // EX-104 : chaque requête porte les identifiants configurés.
        Http::fake();

        DB::connection('servicenow')->connect();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('alice:secret');
        });
    }
}
