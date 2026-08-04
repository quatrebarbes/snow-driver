<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Exceptions;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Tests\TestCase;

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
}
