<?php

namespace Quatrebarbes\SnowDriver\Auth;

use Illuminate\Http\Client\PendingRequest;

/**
 * EX-103 : abstraction permettant l'ajout futur d'un mode d'authentification
 * (ex. OAuth2 client credentials) sans changer l'API publique du driver.
 */
abstract class Credentials
{
    /**
     * Applique ce mode d'authentification à la requête HTTP sortante.
     *
     * EX-104 : chaque requête doit porter les identifiants configurés.
     */
    abstract public function applyTo(PendingRequest $request): PendingRequest;

    /**
     * Représentation sûre pour les journaux applicatifs (EX-104) : aucune
     * valeur secrète n'y apparaît en clair.
     */
    abstract public function toLogArray(): array;

    public static function fromConfig(array $config): self
    {
        $mode = $config['mode'] ?? null;

        return match ($mode) {
            'basic' => BasicAuthCredentials::fromConfig($config),
            default => throw new \InvalidArgumentException(
                "Mode d'authentification ServiceNow non supporté : ".var_export($mode, true).'.'
            ),
        };
    }
}
