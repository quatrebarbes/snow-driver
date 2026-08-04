<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use RuntimeException;

/**
 * EX-128 : une clause du query builder Eloquent sans équivalent dans la
 * syntaxe de requête de l'API Table de ServiceNow (sysparm_query) lève cette
 * exception, plutôt que de produire une traduction silencieuse incorrecte.
 *
 * EX-320 : cette exception reste volontairement une RuntimeException pure, et
 * n'est donc pas une QueryException contrairement aux erreurs d'API (EX-318) :
 * une limite du driver ne doit jamais être présentée par une application hôte
 * comme une violation de contrainte imputable aux valeurs saisies par
 * l'utilisateur.
 */
class ServiceNowUnsupportedQueryException extends RuntimeException
{
    public static function forClause(string $description): self
    {
        return new self("Clause de requête non supportée par le driver ServiceNow : {$description}.");
    }
}
