<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Exceptions;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowMalformedResponseException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\TestCase;
use RuntimeException;

class ServiceNowApiExceptionTest extends TestCase
{
    private function response(int $status, array $body = []): Response
    {
        return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    public function test_it_carries_the_http_status_code_and_servicenow_error_message(): void
    {
        // EX-119 : le code et le message d'erreur ServiceNow sont portés
        // par l'exception.
        $exception = ServiceNowApiException::fromResponse($this->response(400, [
            'error' => ['message' => 'Requête invalide', 'detail' => 'Champ "name" manquant'],
            'status' => 'failure',
        ]));

        $this->assertSame(400, $exception->statusCode());
        $this->assertSame('Requête invalide', $exception->serviceNowMessage());
        $this->assertSame('Champ "name" manquant', $exception->serviceNowDetail());
    }

    public function test_it_maps_a_generic_server_error_to_the_base_api_exception(): void
    {
        $exception = ServiceNowApiException::fromResponse($this->response(500, [
            'error' => ['message' => 'Erreur interne'],
        ]));

        $this->assertInstanceOf(ServiceNowApiException::class, $exception);
        $this->assertNotInstanceOf(ServiceNowAuthenticationException::class, $exception);
        $this->assertSame(500, $exception->statusCode());
    }

    public function test_it_tolerates_a_body_without_an_error_payload(): void
    {
        $exception = ServiceNowApiException::fromResponse($this->response(404, []));

        $this->assertSame(404, $exception->statusCode());
        $this->assertNull($exception->serviceNowMessage());
        $this->assertNull($exception->serviceNowDetail());
    }

    public function test_authentication_exception_exposes_the_same_servicenow_details(): void
    {
        // EX-120 : la sous-classe dédiée porte les mêmes informations que
        // l'exception API générique.
        $exception = ServiceNowAuthenticationException::fromResponse($this->response(401, [
            'error' => ['message' => 'Authentification requise'],
        ]));

        $this->assertInstanceOf(ServiceNowApiException::class, $exception);
        $this->assertSame(401, $exception->statusCode());
        $this->assertSame('Authentification requise', $exception->serviceNowMessage());
    }

    public function test_an_api_error_is_recognisable_as_a_database_error(): void
    {
        // EX-318 : un outil hôte générique traite l'échec comme celui d'une
        // requête SQL, sans connaître le driver.
        $exception = ServiceNowApiException::fromResponse($this->response(400, [
            'error' => ['message' => 'Requête invalide'],
        ]));

        $this->assertInstanceOf(QueryException::class, $exception);
        // Rétrocompatibilité : QueryException est elle-même une RuntimeException,
        // donc tout code capturant l'ancienne hiérarchie (dont saveMany,
        // EX-123) continue de fonctionner.
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function test_a_malformed_response_is_recognisable_as_a_database_error(): void
    {
        // EX-318 : même traitement pour EX-130.
        $exception = ServiceNowMalformedResponseException::forInvalidBody('<html>oups</html>');

        $this->assertInstanceOf(QueryException::class, $exception);
    }

    public function test_an_api_error_keeps_its_business_message(): void
    {
        // EX-119, EX-318 : le message reste celui du driver, pas celui que
        // QueryException composerait autour d'une requête SQL inexistante.
        $exception = ServiceNowApiException::fromResponse($this->response(400, [
            'error' => ['message' => 'Requête invalide'],
        ]));

        $this->assertSame('Erreur API ServiceNow [400] : Requête invalide', $exception->getMessage());
    }

    public function test_an_api_error_carries_the_connection_name_and_the_called_uri(): void
    {
        // EX-319
        $exception = ServiceNowApiException::fromResponse(
            $this->response(400, ['error' => ['message' => 'Requête invalide']]),
            'servicenow_prod',
            '/api/now/table/incident'
        );

        $this->assertSame('servicenow_prod', $exception->getConnectionName());
        $this->assertSame('/api/now/table/incident', $exception->getSql());
    }

    public function test_the_servicenow_error_remains_available_as_the_previous_exception(): void
    {
        // EX-319 : la cause réelle reste accessible comme pour tout échec de
        // requête Laravel.
        $exception = ServiceNowApiException::fromResponse($this->response(500, [
            'error' => ['message' => 'Erreur interne'],
        ]));

        $this->assertNotNull($exception->getPrevious());
        $this->assertStringContainsString('Erreur interne', $exception->getPrevious()->getMessage());
    }

    public function test_an_unsupported_clause_is_not_a_database_error(): void
    {
        // EX-320 : une limite du driver ne doit jamais être présentée comme
        // une violation de contrainte imputable aux valeurs saisies.
        $exception = ServiceNowUnsupportedQueryException::forClause('jointure (join)');

        $this->assertNotInstanceOf(QueryException::class, $exception);
    }

    public function test_a_connection_failure_is_not_a_database_error(): void
    {
        // EX-320, EX-126 : l'injoignabilité de l'instance relève de
        // l'infrastructure, pas de l'échec d'une requête.
        $exception = ServiceNowConnectionException::invalidConfiguration("l'URL de base est manquante");

        $this->assertNotInstanceOf(QueryException::class, $exception);
    }
}
