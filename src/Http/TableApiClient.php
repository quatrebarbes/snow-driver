<?php

namespace Quatrebarbes\SnowDriver\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowMalformedResponseException;

/**
 * Client HTTP interne encapsulant les appels à l'API Table de ServiceNow.
 * Fondation transverse utilisée par le mapping des modèles, le query
 * builder et les relations (Phases 3, 4, 6, 7).
 */
class TableApiClient
{
    public function __construct(private readonly ServiceNowConnection $connection)
    {
    }

    /**
     * EX-133 : sysparm_exclude_reference_link=true est appliqué par défaut à
     * toute lecture (consultation des enregistrements, résolution d'une
     * relation belongsTo, interrogation du dictionnaire) — un champ
     * reference est ainsi toujours renvoyé sous la forme
     * `{value, display_value}`, jamais `{value, link}`, sans alourdir chaque
     * appelant. Un appelant fournissant explicitement ce paramètre conserve
     * la main (union de tableaux : $query prioritaire).
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->decodeResult($this->send('get', $uri, $query + [
            'sysparm_exclude_reference_link' => 'true',
        ]), $uri);
    }

    /** @return array<string, mixed> */
    public function post(string $uri, array $payload): array
    {
        return $this->decodeResult($this->send('post', $uri, $payload), $uri);
    }

    /** @return array<string, mixed> */
    public function put(string $uri, array $payload): array
    {
        return $this->decodeResult($this->send('put', $uri, $payload), $uri);
    }

    /** @return array<string, mixed> */
    public function patch(string $uri, array $payload): array
    {
        return $this->decodeResult($this->send('patch', $uri, $payload), $uri);
    }

    public function delete(string $uri): void
    {
        $this->send('delete', $uri);
    }

    private function send(string $method, string $uri, array $data = []): Response
    {
        try {
            $response = $this->connection->httpClient()->{$method}($uri, $data);
        } catch (ConnectionException $e) {
            throw ServiceNowMalformedResponseException::forNetworkFailure(
                $uri,
                $e,
                $this->connection->connectionName()
            );
        }

        $this->assertSuccessful($response, $uri);

        return $response;
    }

    /**
     * EX-119, EX-120 : mapping du statut HTTP vers l'exception dédiée.
     * EX-319 : nom de la connexion et URI appelée portés par l'exception, pour
     * qu'une application hôte générique puisse les restituer.
     */
    private function assertSuccessful(Response $response, string $uri): void
    {
        if ($response->successful()) {
            return;
        }

        $connectionName = $this->connection->connectionName();

        if (in_array($response->status(), [401, 403], true)) {
            throw ServiceNowAuthenticationException::fromResponse($response, $connectionName, $uri);
        }

        throw ServiceNowApiException::fromResponse($response, $connectionName, $uri);
    }

    /**
     * EX-130 : une réponse sans corps JSON exploitable est rejetée
     * explicitement plutôt que de produire un résultat par défaut trompeur.
     *
     * @return array<string, mixed>
     */
    private function decodeResult(Response $response, string $uri): array
    {
        $body = $response->body();
        $decoded = $response->json();
        $connectionName = $this->connection->connectionName();

        if (trim($body) === '' || ! is_array($decoded)) {
            throw ServiceNowMalformedResponseException::forInvalidBody($body, $connectionName, $uri);
        }

        if (! array_key_exists('result', $decoded) || ! is_array($decoded['result'])) {
            throw ServiceNowMalformedResponseException::forMissingResult($body, $connectionName, $uri);
        }

        return $decoded['result'];
    }
}
