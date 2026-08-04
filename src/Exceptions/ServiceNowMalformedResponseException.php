<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * EX-130 : une réponse malformée ou vide de l'API ServiceNow (coupure
 * réseau, timeout partiel) déclenche une exception dédiée plutôt qu'un
 * plantage silencieux ou un résultat par défaut trompeur.
 *
 * EX-318 : cette exception est reconnaissable par l'application hôte comme une
 * erreur de base de données (QueryException) tout en restant un type distinct,
 * afin qu'un outil générique la traite comme il traiterait l'échec d'une
 * requête SQL, sans connaître le driver. QueryException étant elle-même une
 * RuntimeException, tout code capturant l'ancienne hiérarchie continue de
 * fonctionner.
 */
class ServiceNowMalformedResponseException extends QueryException
{
    public function __construct(string $message, string $connectionName = 'servicenow', string $uri = '', ?Throwable $previous = null)
    {
        // QueryException exige une exception précédente : elle porte ici la
        // cause réelle (réponse inexploitable), et reste accessible via
        // getPrevious() comme pour tout échec de requête Laravel.
        parent::__construct($connectionName, $uri, [], $previous ?? new RuntimeException($message));

        // Le message composé par QueryException (« ... (Connection: ..., SQL:
        // ... ) ») supposerait une requête SQL : le message métier du driver,
        // qui nomme la réponse fautive, lui est préféré.
        $this->message = $message;
    }

    public static function forInvalidBody(string $body, string $connectionName = 'servicenow', string $uri = ''): self
    {
        $preview = $body === '' ? '(corps vide)' : substr($body, 0, 200);

        return new self(
            "Réponse de l'API ServiceNow vide ou dont le corps n'est pas un JSON valide : {$preview}",
            $connectionName,
            $uri
        );
    }

    public static function forMissingResult(string $body, string $connectionName = 'servicenow', string $uri = ''): self
    {
        return new self(
            "Réponse de l'API ServiceNow sans clé 'result' attendue : ".substr($body, 0, 200),
            $connectionName,
            $uri
        );
    }

    public static function forNetworkFailure(string $uri, Throwable $previous, string $connectionName = 'servicenow'): self
    {
        return new self(
            "Réponse de l'API ServiceNow interrompue (coupure réseau ou timeout partiel) pour [{$uri}].",
            $connectionName,
            $uri,
            $previous
        );
    }

    /**
     * EX-314, EX-130 : réponse de la fonction d'agrégation dépourvue de
     * compteur exploitable — distinguée d'un comptage à zéro, qui serait un
     * résultat par défaut trompeur.
     */
    public static function forMissingAggregate(string $table, string $connectionName = 'servicenow'): self
    {
        return new self(
            "Réponse d'agrégation de l'API ServiceNow sans compteur exploitable pour la table [{$table}].",
            $connectionName,
            '/api/now/stats/'.$table
        );
    }
}
