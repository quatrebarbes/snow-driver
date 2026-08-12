<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowMalformedResponseException;
use Quatrebarbes\SnowDriver\Tests\TestCase;

class TableApiClientTest extends TestCase
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

    private function client()
    {
        /** @var ServiceNowConnection $connection */
        $connection = DB::connection('servicenow');

        return $connection->tableApi();
    }

    public function test_a_successful_get_returns_the_decoded_result(): void
    {
        Http::fake([
            '*/api/now/table/incident*' => Http::response(['result' => [['sys_id' => 'abc123']]], 200),
        ]);

        $result = $this->client()->get('/api/now/table/incident');

        $this->assertSame([['sys_id' => 'abc123']], $result);
    }

    public function test_a_401_response_throws_a_dedicated_authentication_exception(): void
    {
        // EX-120
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Authentification requise']], 401),
        ]);

        $this->expectException(ServiceNowAuthenticationException::class);

        $this->client()->get('/api/now/table/incident');
    }

    public function test_a_403_response_throws_a_dedicated_authentication_exception(): void
    {
        // EX-120
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Accès refusé']], 403),
        ]);

        try {
            $this->client()->get('/api/now/table/incident');
            $this->fail('Une exception ServiceNowAuthenticationException était attendue.');
        } catch (ServiceNowAuthenticationException $e) {
            $this->assertSame(403, $e->statusCode());
            $this->assertSame('Accès refusé', $e->serviceNowMessage());
        }
    }

    public function test_a_generic_4xx_response_throws_the_base_api_exception_without_authentication_subtype(): void
    {
        // EX-119
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Requête invalide']], 400),
        ]);

        try {
            $this->client()->get('/api/now/table/incident');
            $this->fail('Une exception ServiceNowApiException était attendue.');
        } catch (ServiceNowApiException $e) {
            $this->assertNotInstanceOf(ServiceNowAuthenticationException::class, $e);
            $this->assertSame(400, $e->statusCode());
            $this->assertSame('Requête invalide', $e->serviceNowMessage());
        }
    }

    public function test_a_5xx_response_throws_the_base_api_exception(): void
    {
        // EX-119
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Erreur interne']], 500),
        ]);

        try {
            $this->client()->get('/api/now/table/incident');
            $this->fail('Une exception ServiceNowApiException était attendue.');
        } catch (ServiceNowApiException $e) {
            $this->assertSame(500, $e->statusCode());
        }
    }

    public function test_an_empty_response_body_throws_a_dedicated_malformed_response_exception(): void
    {
        // EX-130
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $this->expectException(ServiceNowMalformedResponseException::class);

        $this->client()->get('/api/now/table/incident');
    }

    public function test_a_non_json_response_body_throws_a_dedicated_malformed_response_exception(): void
    {
        // EX-130
        Http::fake([
            '*' => Http::response('<html>gateway timeout</html>', 200),
        ]);

        $this->expectException(ServiceNowMalformedResponseException::class);

        $this->client()->get('/api/now/table/incident');
    }

    public function test_a_json_body_without_a_result_key_throws_a_dedicated_malformed_response_exception(): void
    {
        // EX-130
        Http::fake([
            '*' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        $this->expectException(ServiceNowMalformedResponseException::class);

        $this->client()->get('/api/now/table/incident');
    }

    public function test_a_mid_request_connection_failure_throws_a_dedicated_malformed_response_exception(): void
    {
        // EX-130 : coupure réseau/timeout partiel lors d'un appel Table API
        // (à distinguer de EX-126, propre à l'établissement de connexion).
        Http::fake(Http::failedConnection());

        $this->expectException(ServiceNowMalformedResponseException::class);

        $this->client()->get('/api/now/table/incident');
    }

    public function test_a_successful_delete_does_not_attempt_to_decode_the_empty_body(): void
    {
        Http::fake([
            '*/api/now/table/incident/abc123' => Http::response('', 204),
        ]);

        $this->client()->delete('/api/now/table/incident/abc123');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_credentials_are_attached_to_table_api_requests(): void
    {
        // EX-104 : chaque requête vers l'API Table porte les identifiants
        // configurés pour la connexion active.
        Http::fake([
            '*' => Http::response(['result' => []], 200),
        ]);

        $this->client()->get('/api/now/table/incident');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('alice:secret');
        });
    }

    public function test_a_get_request_omits_the_reference_link_by_default(): void
    {
        // EX-133 : sysparm_exclude_reference_link=true est demandé par défaut
        // sur toute lecture, sans que l'appelant n'ait à le fournir.
        Http::fake([
            '*' => Http::response(['result' => []], 200),
        ]);

        $this->client()->get('/api/now/table/incident');

        Http::assertSent(fn ($request) => ($request['sysparm_exclude_reference_link'] ?? null) === 'true');
    }

    public function test_a_caller_supplied_reference_link_value_takes_precedence(): void
    {
        // EX-133 : un appelant fournissant lui-même le paramètre conserve la
        // main sur la valeur par défaut.
        Http::fake([
            '*' => Http::response(['result' => []], 200),
        ]);

        $this->client()->get('/api/now/table/incident', ['sysparm_exclude_reference_link' => 'false']);

        Http::assertSent(fn ($request) => ($request['sysparm_exclude_reference_link'] ?? null) === 'false');
    }
}
