<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * EX-119 : toute réponse HTTP 4xx/5xx de l'API ServiceNow est traduite en
 * exception dédiée portant le code et le message d'erreur ServiceNow.
 *
 * EX-318, EX-319 : cette exception est reconnaissable par l'application hôte
 * comme une erreur de base de données (QueryException) — un outil générique la
 * traite alors comme l'échec d'une requête SQL, sans connaître le driver — tout
 * en conservant son type propre et celui, distinct, de l'erreur
 * d'authentification (EX-120). Elle porte le nom de la connexion concernée,
 * accessible par getConnectionName(), et le message renvoyé par ServiceNow.
 *
 * QueryException étant elle-même une RuntimeException, tout code capturant
 * l'ancienne hiérarchie (dont saveMany, EX-123) continue de fonctionner.
 */
class ServiceNowApiException extends QueryException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly ?string $serviceNowMessage,
        private readonly ?string $serviceNowDetail,
        string $connectionName = 'servicenow',
        string $uri = '',
    ) {
        $message = sprintf(
            'Erreur API ServiceNow [%d] : %s',
            $statusCode,
            $serviceNowMessage ?? 'erreur inconnue'
        );

        // QueryException exige une exception précédente : elle porte ici la
        // cause réelle (l'erreur renvoyée par l'instance), et reste accessible
        // via getPrevious() comme pour tout échec de requête Laravel.
        parent::__construct($connectionName, $uri, [], new RuntimeException($message, $statusCode));

        // Le message composé par QueryException (« ... (Connection: ..., SQL:
        // ...) ») supposerait une requête SQL : le message métier du driver,
        // qui porte le code et le message ServiceNow (EX-119), lui est préféré.
        $this->message = $message;
    }

    public static function fromResponse(Response $response, string $connectionName = 'servicenow', string $uri = ''): static
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        return new static(
            $response->status(),
            $error['message'] ?? null,
            $error['detail'] ?? null,
            $connectionName,
            $uri,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function serviceNowMessage(): ?string
    {
        return $this->serviceNowMessage;
    }

    public function serviceNowDetail(): ?string
    {
        return $this->serviceNowDetail;
    }
}
