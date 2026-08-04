<?php

namespace Quatrebarbes\SnowDriver\Connection;

use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Quatrebarbes\SnowDriver\Auth\Credentials;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;

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
