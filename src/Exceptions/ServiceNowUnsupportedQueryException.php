<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use RuntimeException;

/**
 * EX-128 : une clause du query builder Eloquent sans équivalent dans la
 * syntaxe de requête de l'API Table de ServiceNow (sysparm_query) lève cette
 * exception, plutôt que de produire une traduction silencieuse incorrecte.
 */
class ServiceNowUnsupportedQueryException extends RuntimeException
{
    public static function forClause(string $description): self
    {
        return new self("Clause de requête non supportée par le driver ServiceNow : {$description}.");
    }
}
