<?php

namespace App\Models;

use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

/**
 * Modèle générique utilisé par l'application de démo pour parcourir
 * n'importe quelle table ServiceNow choisie dans le menu, sans déclarer
 * une classe dédiée par table.
 */
class ServiceNowRecord extends ServiceNowModel
{
    protected $guarded = [];

    public static function forTable(string $table): static
    {
        return (new static())->setTable($table);
    }

    /**
     * Représentation affichable d'un champ : les champs de référence et
     * certains choix sont retournés par l'API Table sous forme de tableau
     * (ex. ['value' => ..., 'link' => ...]) plutôt qu'une valeur scalaire.
     */
    public function display(string $key): string
    {
        $value = $this->getAttribute($key);

        if (is_array($value)) {
            return (string) ($value['value'] ?? '');
        }

        return (string) ($value ?? '');
    }
}
