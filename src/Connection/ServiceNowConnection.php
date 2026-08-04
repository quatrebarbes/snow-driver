<?php

namespace Quatrebarbes\SnowDriver\Connection;

use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Quatrebarbes\SnowDriver\Auth\Credentials;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;
use Quatrebarbes\SnowDriver\Http\TableApiClient;
use Quatrebarbes\SnowDriver\Query\ServiceNowGrammar;

/**
 * EX-101 : connexion à une instance ServiceNow configurée via config/database.php.
 */
class ServiceNowConnection extends Connection
{
    private ?Credentials $credentials = null;

    public function __construct(string $database, string $tablePrefix, array $config)
    {
        // EX-121 : on réutilise le mécanisme de résolution différée de
        // Connection::getPdo() (fermeture invoquée une seule fois, au premier
        // appel) pour ne rien valider avant la première requête effective.
        // Ce driver n'a pas de PDO ; la fermeture vérifie la joignabilité de
        // l'instance ServiceNow à la place.
        parent::__construct(fn () => $this->establishConnection(), $database, $tablePrefix, $config);
    }

    public function connect(): void
    {
        $this->getPdo();
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->getConfig('base_url'), '/');
    }

    public function timeout(): int
    {
        return (int) ($this->getConfig('timeout') ?? 30);
    }

    public function credentials(): Credentials
    {
        if (! $this->credentials instanceof Credentials) {
            try {
                $this->credentials = Credentials::fromConfig($this->getConfig('auth') ?? []);
            } catch (InvalidArgumentException $e) {
                throw ServiceNowConnectionException::invalidConfiguration($e->getMessage());
            }
        }

        return $this->credentials;
    }

    /**
     * EX-104 : chaque requête envoyée à l'API ServiceNow porte les
     * identifiants de la connexion active.
     */
    public function httpClient(): PendingRequest
    {
        return $this->credentials()->applyTo(
            Http::baseUrl($this->baseUrl())->timeout($this->timeout())
        );
    }

    /**
     * Client HTTP interne pour les appels à l'API Table (Phase 2).
     */
    public function tableApi(): TableApiClient
    {
        return new TableApiClient($this);
    }

    protected function getDefaultQueryGrammar(): ServiceNowGrammar
    {
        return new ServiceNowGrammar($this);
    }

    /**
     * EX-108, EX-109, EX-110, EX-111 : exécute la requête compilée par
     * ServiceNowGrammar (table + sysparm_query + limite/décalage) via l'API
     * Table, au lieu du cycle PDO standard.
     *
     * On n'utilise volontairement pas Connection::run() : celui-ci
     * envelopperait toute exception (ServiceNowApiException,
     * ServiceNowAuthenticationException...) dans une Illuminate\Database\QueryException
     * générique, ce qui casserait la distinction attendue par EX-119/EX-120/EX-130.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $decoded = json_decode($query, true);

        $table = $decoded['table'];
        $sysparmQuery = $decoded['query'];
        $fields = $decoded['fields'];
        $limit = $decoded['limit'];
        $offset = $decoded['offset'] ?? 0;

        $params = array_filter([
            'sysparm_query' => $sysparmQuery !== '' ? $sysparmQuery : null,
            'sysparm_fields' => $fields !== null ? implode(',', $fields) : null,
        ], fn ($value) => $value !== null);

        if ($limit !== null) {
            return $this->tableApi()->get('/api/now/table/'.$table, $params + [
                'sysparm_limit' => $limit,
                'sysparm_offset' => $offset,
            ]);
        }

        return $this->selectAllPages($table, $params, $offset);
    }

    /**
     * EX-122 : pagination automatique et transparente pour all()/get() sans
     * limite explicite, en enchaînant les appels avec un décalage croissant
     * tant que l'API retourne une page pleine.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function selectAllPages(string $table, array $params, int $offset): array
    {
        $pageSize = (int) config('servicenow.pagination.page_size');

        $records = [];

        do {
            $page = $this->tableApi()->get('/api/now/table/'.$table, $params + [
                'sysparm_limit' => $pageSize,
                'sysparm_offset' => $offset,
            ]);

            $records = array_merge($records, $page);
            $offset += $pageSize;
        } while (count($page) === $pageSize);

        return $records;
    }

    private function establishConnection(): object
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === '') {
            throw ServiceNowConnectionException::invalidConfiguration("l'URL de base (base_url) est manquante");
        }

        try {
            $this->httpClient()->get('');
        } catch (HttpConnectionException $e) {
            throw ServiceNowConnectionException::unreachable($baseUrl, $e);
        }

        return new \stdClass();
    }

    public function __debugInfo(): array
    {
        $config = $this->config;
        unset($config['auth']);

        return [
            'database' => $this->database,
            'tablePrefix' => $this->tablePrefix,
            'config' => $config,
        ];
    }
}
