<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use RuntimeException;
use Throwable;

/**
 * EX-130 : une réponse malformée ou vide de l'API ServiceNow (coupure
 * réseau, timeout partiel) déclenche une exception dédiée plutôt qu'un
 * plantage silencieux ou un résultat par défaut trompeur.
 */
class ServiceNowMalformedResponseException extends RuntimeException
{
    public static function forInvalidBody(string $body): self
    {
        $preview = $body === '' ? '(corps vide)' : substr($body, 0, 200);

        return new self("Réponse de l'API ServiceNow vide ou dont le corps n'est pas un JSON valide : {$preview}");
    }

    public static function forMissingResult(string $body): self
    {
        return new self(
            "Réponse de l'API ServiceNow sans clé 'result' attendue : ".substr($body, 0, 200)
        );
    }

    public static function forNetworkFailure(string $uri, Throwable $previous): self
    {
        return new self(
            "Réponse de l'API ServiceNow interrompue (coupure réseau ou timeout partiel) pour [{$uri}].",
            0,
            $previous
        );
    }
}
