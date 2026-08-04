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

    /** @return array<int, array<string, mixed>> */
    public function get(string $uri, array $query = []): array
    {
        return $this->decodeResult($this->send('get', $uri, $query));
    }

    /** @return array<string, mixed> */
    public function post(string $uri, array $payload): array
    {
        return $this->decodeResult($this->send('post', $uri, $payload));
    }

    /** @return array<string, mixed> */
    public function put(string $uri, array $payload): array
    {
        return $this->decodeResult($this->send('put', $uri, $payload));
    }

    /** @return array<string, mixed> */
    public function patch(string $uri, array $payload): array
    {
        return $this->decodeResult($this->send('patch', $uri, $payload));
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
            throw ServiceNowMalformedResponseException::forNetworkFailure($uri, $e);
        }

        $this->assertSuccessful($response);

        return $response;
    }

    /**
     * EX-119, EX-120 : mapping du statut HTTP vers l'exception dédiée.
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw ServiceNowAuthenticationException::fromResponse($response);
        }

        throw ServiceNowApiException::fromResponse($response);
    }

    /**
     * EX-130 : une réponse sans corps JSON exploitable est rejetée
     * explicitement plutôt que de produire un résultat par défaut trompeur.
     *
     * @return array<string, mixed>
     */
    private function decodeResult(Response $response): array
    {
        $body = $response->body();
        $decoded = $response->json();

        if (trim($body) === '' || ! is_array($decoded)) {
            throw ServiceNowMalformedResponseException::forInvalidBody($body);
        }

        if (! array_key_exists('result', $decoded) || ! is_array($decoded['result'])) {
            throw ServiceNowMalformedResponseException::forMissingResult($body);
        }

        return $decoded['result'];
    }
}
