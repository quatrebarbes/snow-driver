<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use RuntimeException;
use Throwable;

/**
 * EX-121, EX-126.
 */
class ServiceNowConnectionException extends RuntimeException
{
    public static function unreachable(string $baseUrl, ?Throwable $previous = null): self
    {
        return new self("Impossible de joindre l'instance ServiceNow [{$baseUrl}].", 0, $previous);
    }

    public static function invalidConfiguration(string $reason): self
    {
        return new self("Configuration de connexion ServiceNow invalide : {$reason}.");
    }
}
