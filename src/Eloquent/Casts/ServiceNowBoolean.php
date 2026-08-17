<?php

namespace Quatrebarbes\SnowDriver\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * EX-332 : conversion booléenne partagée entre la chaîne renvoyée par l'API
 * Table ServiceNow ("true"/"false") et le type PHP natif bool. get()/set()
 * implémentent CastsAttributes pour un usage direct via $casts (modèle
 * déclaré manuellement) ; read()/write() sont les mêmes conversions exposées
 * en méthodes statiques, utilisées par l'accessor/mutator get{Champ}
 * Attribute()/set{Champ}Attribute() que ModelFileGenerator génère pour
 * chaque champ booléen (Generator\ModelFileGenerator::
 * renderBooleanAccessors()) — la logique n'est ainsi écrite qu'une seule
 * fois, quel que soit le nombre de champs booléens d'un modèle généré.
 *
 * EX-336 : le générateur nomme cet accessor/mutator selon la convention
 * historique get/set...Attribute() plutôt que par une méthode portant
 * exactement le nom du champ (Attribute::make()), afin qu'aucun nom de champ
 * ServiceNow ne puisse jamais entrer en collision avec une méthode statique
 * native d'Eloquent (ex. Model::deleted($callback)) — cette classe n'a donc
 * plus besoin d'être un mécanisme de repli distinct pour ces champs.
 */
class ServiceNowBoolean implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?bool
    {
        return self::read($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::write($value);
    }

    public static function read(mixed $value): ?bool
    {
        return $value === null ? null : (is_bool($value) ? $value : strtolower((string) $value) === 'true');
    }

    public static function write(mixed $value): ?string
    {
        return $value === null ? null : ($value ? 'true' : 'false');
    }
}
