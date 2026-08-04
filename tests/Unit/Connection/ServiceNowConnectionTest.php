<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Connection;

use Quatrebarbes\SnowDriver\Auth\BasicAuthCredentials;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;
use Quatrebarbes\SnowDriver\Tests\TestCase;

class ServiceNowConnectionTest extends TestCase
{
    public function test_constructing_the_connection_never_throws_even_with_invalid_config(): void
    {
        // EX-121 : une configuration absente ou invalide ne doit pas être
        // validée à la construction, seulement à la première requête.
        $connection = new ServiceNowConnection('', '', []);

        $this->assertInstanceOf(ServiceNowConnection::class, $connection);
    }

    public function test_it_exposes_the_configured_base_url_without_a_trailing_slash(): void
    {
        $connection = new ServiceNowConnection('', '', [
            'base_url' => 'https://dev12345.service-now.com/',
        ]);

        $this->assertSame('https://dev12345.service-now.com', $connection->baseUrl());
    }

    public function test_it_defaults_the_timeout_to_thirty_seconds(): void
    {
        $connection = new ServiceNowConnection('', '', []);

        $this->assertSame(30, $connection->timeout());
    }

    public function test_it_honors_the_configured_timeout(): void
    {
        $connection = new ServiceNowConnection('', '', ['timeout' => 5]);

        $this->assertSame(5, $connection->timeout());
    }

    public function test_it_selects_basic_auth_credentials_from_config(): void
    {
        $connection = new ServiceNowConnection('', '', [
            'auth' => ['mode' => 'basic', 'username' => 'alice', 'password' => 'secret'],
        ]);

        $this->assertInstanceOf(BasicAuthCredentials::class, $connection->credentials());
    }

    public function test_credentials_resolution_is_deferred_and_wraps_invalid_configuration(): void
    {
        $connection = new ServiceNowConnection('', '', [
            'auth' => ['mode' => 'unsupported'],
        ]);

        $this->expectException(ServiceNowConnectionException::class);

        $connection->credentials();
    }

    public function test_connecting_without_a_base_url_throws_a_dedicated_exception(): void
    {
        $connection = new ServiceNowConnection('', '', [
            'auth' => ['mode' => 'basic', 'username' => 'alice', 'password' => 'secret'],
        ]);

        $this->expectException(ServiceNowConnectionException::class);

        $connection->connect();
    }
}
