<?php

namespace Quatrebarbes\SnowDriver\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * EX-327 : conversion Eloquent dédiée aux champs booléens ServiceNow.
 *
 * L'API Table de ServiceNow renvoie ces champs sous forme de chaîne
 * ("true"/"false"), jamais de booléen JSON natif. Le cast natif 'boolean'
 * d'Eloquent applique un simple `(bool) $value`, qui vaut toujours `true`
 * pour une chaîne non vide - y compris littéralement la chaîne "false" -
 * d'où la nécessité de cette conversion explicite.
 */
class ServiceNowBoolean implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return strtolower((string) $value) === 'true';
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value ? 'true' : 'false';
    }
}
