<?php

namespace Quatrebarbes\SnowDriver\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EX-129 : un champ de référence ServiceNow vide est une chaîne vide (champ
 * de type string côté ServiceNow), pas null. BelongsTo::getForeignKeyFrom()
 * ne teste que is_null(), ce qui laisserait passer un appel inutile à l'API
 * Table pour une référence vide avant de constater l'absence de résultat.
 * On normalise ici la chaîne vide en null pour que la relation soit
 * résolue à null directement, sans requête.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsTo<TRelatedModel, TDeclaringModel>
 */
class ServiceNowBelongsTo extends BelongsTo
{
    protected function getForeignKeyFrom(Model $model)
    {
        $value = parent::getForeignKeyFrom($model);

        return $value === '' ? null : $value;
    }
}
